<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Infrastructure\Inventory;

use App\Fiscal\Application\Inventory\FiscalInventoryCatalogue;
use App\Fiscal\Application\Inventory\FiscalRecordKind;
use App\Fiscal\Application\Inventory\FiscalRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;

/**
 * Counts every member of the fiscal inventory, for every company or one, in a few queries a member: read-only, across
 * companies, so a script reads it before a wipe whatever company the request would have acted for. Each member's own
 * condition picks its rows; the first and last numbers are those of the first and last documents by day.
 *
 * @phpstan-type Line array{code: string, kind: string, count: int, pdfs: ?int, firstNumber: ?string, lastNumber: ?string, from: ?string, to: ?string}
 * @phpstan-type CompanyInventory array{id: string, name: string, status: string, retainUntil: ?string, fiscal: bool, records: list<Line>}
 */
final readonly class DbalFiscalInventory
{
    public function __construct(private Connection $connection, private FiscalInventoryCatalogue $catalogue)
    {
    }

    /**
     * How many fiscal records the companies hold together, the number a wipe's typed sentence names.
     *
     * @param list<CompanyInventory> $companies
     */
    public static function fiscalRecords(array $companies): int
    {
        $records = 0;
        foreach ($companies as $company) {
            foreach ($company['records'] as $line) {
                $records += FiscalRecordKind::Fiscal->value === $line['kind'] ? $line['count'] : 0;
            }
        }

        return $records;
    }

    /**
     * @return list<CompanyInventory> every company, or only the one named, by name
     */
    public function companies(?string $only = null): array
    {
        $companies = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id::text AS id, name, status, retain_until::text AS retain_until FROM company'.(null === $only ? '' : ' WHERE id = ?').' ORDER BY name, id',
            null === $only ? [] : [$only],
        ) as $row) {
            $companies[Rows::text($row['id'])] = ['id' => Rows::text($row['id']), 'name' => Rows::text($row['name']), 'status' => Rows::text($row['status']), 'retainUntil' => Rows::textOrNull($row['retain_until'])];
        }
        $counted = [];
        foreach ($this->catalogue->companyRecords() as $records) {
            $counted[$records->code] = $this->counted($records, $only);
        }

        $inventories = [];
        foreach ($companies as $id => $company) {
            $lines = [];
            $fiscal = false;
            foreach ($this->catalogue->companyRecords() as $records) {
                $line = $counted[$records->code][$id] ?? self::none($records);
                $fiscal = $fiscal || (FiscalRecordKind::Fiscal === $records->kind && $line['count'] > 0);
                $lines[] = $line;
            }
            $inventories[] = [...$company, 'fiscal' => $fiscal, 'records' => $lines];
        }

        return $inventories;
    }

    /** @return list<Line> the members the platform holds, which belong to no company */
    public function platform(): array
    {
        return array_map(function (FiscalRecords $records): array {
            $line = self::none($records);
            $line['count'] = Rows::int($this->connection->fetchOne(\sprintf('SELECT COUNT(*) FROM %s WHERE (%s)', $records->table, $records->where)));

            return $line;
        }, $this->catalogue->platformRecords());
    }

    /** @return array<string, Line> by company id, the companies holding none left out */
    private function counted(FiscalRecords $records, ?string $only): array
    {
        $company = $records->companyColumn ?? throw new \LogicException("$records->code belongs to no company.");
        $scope = null === $only ? '' : " AND $company = ?";
        $parameters = null === $only ? [] : [$only];
        $date = $records->dateColumn;
        $lines = [];
        foreach ($this->connection->fetchAllAssociative(\sprintf(
            'SELECT %1$s::text AS company, COUNT(*) AS n, %2$s AS pdfs, %3$s AS first_day, %4$s AS last_day FROM %5$s WHERE (%6$s) AND %1$s IS NOT NULL%7$s GROUP BY %1$s',
            $company,
            null === $records->pdfColumn ? 'NULL::bigint' : "COUNT($records->pdfColumn)",
            null === $date ? 'NULL::text' : "MIN($date)::text",
            null === $date ? 'NULL::text' : "MAX($date)::text",
            $records->table,
            $records->where,
            $scope,
        ), $parameters) as $row) {
            $line = self::none($records);
            $line['count'] = Rows::int($row['n']);
            $line['pdfs'] = null === $records->pdfColumn ? null : Rows::int($row['pdfs']);
            $line['from'] = Rows::textOrNull($row['first_day']);
            $line['to'] = Rows::textOrNull($row['last_day']);
            $lines[Rows::text($row['company'])] = $line;
        }
        if (null !== $records->numberColumn) {
            foreach (['firstNumber' => 'ASC', 'lastNumber' => 'DESC'] as $key => $direction) {
                foreach ($this->firstNumbers($records, $records->numberColumn, $company, $direction, $scope, $parameters) as $id => $number) {
                    if (isset($lines[$id])) {
                        $lines[$id][$key] = $number;
                    }
                }
            }
        }

        return $lines;
    }

    /**
     * @param 'ASC'|'DESC' $direction
     * @param list<string> $parameters
     *
     * @return array<string, string> each company's first number in that direction, by day then number
     */
    private function firstNumbers(FiscalRecords $records, string $number, string $company, string $direction, string $scope, array $parameters): array
    {
        $byDay = null === $records->dateColumn ? '' : "$records->dateColumn $direction, ";
        $numbers = [];
        foreach ($this->connection->fetchAllAssociative(\sprintf(
            'SELECT DISTINCT ON (%1$s) %1$s::text AS company, %2$s AS number FROM %3$s WHERE (%4$s) AND %2$s IS NOT NULL%5$s ORDER BY %1$s, %6$s%2$s %7$s, id %7$s',
            $company,
            $number,
            $records->table,
            $records->where,
            $scope,
            $byDay,
            $direction,
        ), $parameters) as $row) {
            $numbers[Rows::text($row['company'])] = Rows::text($row['number']);
        }

        return $numbers;
    }

    /** @return Line */
    private static function none(FiscalRecords $records): array
    {
        return ['code' => $records->code, 'kind' => $records->kind->value, 'count' => 0, 'pdfs' => null === $records->pdfColumn ? null : 0, 'firstNumber' => null, 'lastNumber' => null, 'from' => null, 'to' => null];
    }
}
