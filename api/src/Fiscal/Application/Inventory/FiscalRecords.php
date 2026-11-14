<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Inventory;

/**
 * One member of the fiscal inventory: the rows of a table its condition picks, counted per company (or once for the
 * platform, when the table belongs to no company). Its parts are the tables whose rows belong to those records (an
 * invoice's lines and taxes) and are counted with them. The condition is SQL over the table's own columns, written by the
 * context that owns the table; FiscalInventoryCoverage holds every table and column named here to the database.
 */
final readonly class FiscalRecords
{
    private const string NAME = '/^[a-z][a-z0-9_]*$/';

    /** @param list<string> $parts */
    private function __construct(
        public string $code,
        public FiscalRecordKind $kind,
        public string $table,
        public string $where,
        public array $parts,
        public ?string $pdfColumn,
        public ?string $numberColumn,
        public ?string $dateColumn,
        public ?string $companyColumn,
    ) {
        foreach ([$table, ...$parts, $pdfColumn, $numberColumn, $dateColumn, $companyColumn] as $name) {
            if (null !== $name && 1 !== preg_match(self::NAME, $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a table or column name the inventory may write into SQL (%s).', $name, $code));
            }
        }
        if (1 !== preg_match('/^[a-z_]+\.[a-z_]+$/', $code)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a member code, <context>.<what>.', $code));
        }
    }

    /**
     * Issued documents or records of their ledger: any of them makes a company's data fiscal.
     *
     * @param list<string> $parts
     */
    public static function fiscal(string $code, string $table, string $where = 'true', array $parts = [], ?string $pdfColumn = null, ?string $numberColumn = null, ?string $dateColumn = null): self
    {
        return new self($code, FiscalRecordKind::Fiscal, $table, $where, $parts, $pdfColumn, $numberColumn, $dateColumn, 'company_id');
    }

    /**
     * Records kept beside them, listed and exported but never making the data fiscal on their own; a null company
     * column counts them once for the whole platform.
     *
     * @param list<string> $parts
     */
    public static function kept(string $code, string $table, string $where = 'true', array $parts = [], ?string $dateColumn = null, ?string $companyColumn = 'company_id'): self
    {
        return new self($code, FiscalRecordKind::Kept, $table, $where, $parts, null, null, $dateColumn, $companyColumn);
    }

    /** These records with their stored documents no longer counted, as a context forgetting them would leave them. */
    public function withoutPdf(): self
    {
        return new self($this->code, $this->kind, $this->table, $this->where, $this->parts, null, $this->numberColumn, $this->dateColumn, $this->companyColumn);
    }

    /** @return list<string> the columns of its table it reads */
    public function columns(): array
    {
        return array_values(array_filter([$this->pdfColumn, $this->numberColumn, $this->dateColumn, $this->companyColumn], static fn (?string $column): bool => null !== $column));
    }
}
