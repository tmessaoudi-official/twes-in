<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Doctrine;

use App\Files\Application\FileStorage;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * A company's file rows and the bytes in its folder, read against each other in one pass: the rows whose bytes are gone,
 * and the bytes no row of that company names, with when they were written. A row stays until its record is gone for
 * good, an undo still pending included, so bytes no row names are a write cut short between the two, never a file that
 * may come back. What the data check reports and what the hourly task removes are both read here.
 */
final readonly class StoredBytes
{
    public function __construct(private Connection $connection, private FileStorage $storage)
    {
    }

    /** @return list<Uuid> every company, whose folders are read one by one */
    public function companies(): array
    {
        return array_map(static fn (mixed $id): Uuid => Uuid::fromString(Rows::text($id)), $this->connection->fetchFirstColumn('SELECT id FROM company ORDER BY id'));
    }

    /** @return array{missing: list<string>, unnamed: array<string, \DateTimeImmutable>} */
    public function of(Uuid $company): array
    {
        $rows = array_flip(array_map(Rows::text(...), $this->connection->fetchFirstColumn('SELECT storage_key FROM file WHERE company_id = ?', [$company->toRfc4122()])));
        $missing = $rows;
        $unnamed = [];
        foreach ($this->storage->keysUnder('companies/'.$company->toRfc4122()) as $key => $written) {
            if (isset($rows[$key])) {
                unset($missing[$key]);
            } else {
                $unnamed[$key] = $written;
            }
        }

        return ['missing' => array_keys($missing), 'unnamed' => $unnamed];
    }
}
