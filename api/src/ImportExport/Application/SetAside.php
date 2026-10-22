<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * The doubtful cells of one row, set aside rather than losing the row (docs/SPEC.md § 7, 2026-10-22 18:09): an
 * identifier, a contact or a bank detail the rules refuse is a typo the person corrects on the record, so the row is
 * written without it, the record marked « À vérifier » with what the file held, and the report says it on that cell.
 * Nothing invalid is stored: the cell is left out, never kept as typed.
 */
final class SetAside
{
    /** The refusals that make a cell doubtful rather than the row wrong: each is a value the record can go without. */
    public const array REASONS = [
        'identifier_shape',
        'identifier_checksum',
        'identifier_part',
        'identifier_part_rule',
        'identifier_reversed',
        'invalid_iban',
        'invalid_bic',
        'invalid_phone',
        'invalid_email',
        'invalid_barcode',
    ];

    /** @var array<string, array{value: string, reason: string, params: array<string, string|int>}> by column */
    private array $cells = [];

    /** @var list<RowRejected> the refusals the cells were set aside for, said again when the row is refused all the same */
    private array $doubts = [];

    /**
     * Writes a row, read again without each doubt its rules refuse, until it is written or refused for anything else.
     * Each try notes on its own, so what a refused try said is not said twice.
     *
     * @param callable(ImportRecord, RowNotes): RowImported $row
     */
    public function import(ImportRecord $record, RowNotes $notes, callable $row): RowImported
    {
        while (true) {
            $tried = new RowNotes();
            try {
                $imported = $row($this->without($record), $tried);
            } catch (RowRejected $refused) {
                if ($this->takeAll($refused, $record)) {
                    continue;
                }

                throw $this->refusing($refused);
            }
            $notes->keep($tried);

            return $imported;
        }
    }

    /**
     * Takes every fault of a refused row aside when each is a doubt about a cell the row fills in; a row holding any
     * other fault is refused as it is, its doubts said with the rest, and nothing is taken.
     *
     * @return bool whether they were taken, so the row is read again without them
     */
    public function takeAll(RowRejected $refused, ImportRecord $record): bool
    {
        $taken = [];
        foreach ($refused->all() as $fault) {
            $column = $fault->column;
            $value = null === $column ? null : $record->value($column);
            if (null === $column || null === $value || isset($this->cells[$column]) || !\in_array($fault->reason, self::REASONS, true)) {
                return false;
            }
            $taken[$column] = ['value' => $value, 'reason' => $fault->reason, 'params' => $fault->params];
        }
        $this->cells += $taken;
        array_push($this->doubts, ...$refused->all());

        return true;
    }

    /**
     * The row's refusal once it is refused for something no cell set aside answers: its doubts are said with it, as
     * every fault of a row is, and a fault that only leaving a cell out made (a pack's quantity without its code) gives
     * way to the doubt about that cell.
     */
    private function refusing(RowRejected $refused): RowRejected
    {
        if ([] === $this->doubts) {
            return $refused;
        }
        $faults = array_values(array_filter($refused->all(), fn (RowRejected $fault): bool => null === $fault->column || !isset($this->cells[$fault->column])));

        return RowRejected::together([...$this->doubts, ...$faults]);
    }

    /** The row as it is written: without the cells set aside, which read as left blank. */
    public function without(ImportRecord $record): ImportRecord
    {
        return [] === $this->cells ? $record : new ImportRecord($record->line, array_diff_key($record->values, $this->cells), $record->overflow, $record->unreadable);
    }

    public function has(string $column): bool
    {
        return isset($this->cells[$column]);
    }

    public function any(): bool
    {
        return [] !== $this->cells;
    }

    /** @return array<string, array{value: string, reason: string, params: array<string, string|int>}> by column, in the order found */
    public function cells(): array
    {
        return $this->cells;
    }

    /** Each cell said on the report, its value as typed, why, and that text's own parameters. */
    public function noteOn(RowNotes $notes): void
    {
        foreach ($this->cells as $column => $cell) {
            $notes->note($column, 'set_aside', ['value' => $cell['value'], 'reason' => $cell['reason'], ...$cell['params']]);
        }
    }
}
