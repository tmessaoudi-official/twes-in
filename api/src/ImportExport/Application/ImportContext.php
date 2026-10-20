<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

use Symfony\Component\Uid\Uuid;

/**
 * One run of a file, as every row of it sees it: the id the run is kept under once committed, which what a row writes
 * elsewhere may carry to read as the import's, the switches the person ticked among those the subject offers,
 * whether it is the preview, rolled back whatever it finds, so a row asks for nothing a preview cannot keep, and the
 * subject's columns as this person may see them, the ones a row found again is compared over.
 *
 * It also keeps what no row of the run changes, read for the first row that asks: who imports and what they may see, the
 * company's units, taxes and custom fields. Read again for each row, a file of 2000 rows ran some fifty thousand queries
 * and came close to the time a request is given.
 */
final class ImportContext
{
    /** @var array<string, mixed> */
    private array $known = [];

    /** @var array<string, array<string, Uuid>> what the rows created, by table and id */
    private array $created = [];

    /**
     * @param list<string> $ticked  the keys of the subject's switches the person ticked
     * @param list<string> $columns the subject's columns for this company and person
     */
    public function __construct(
        public readonly Uuid $runId,
        private readonly array $ticked = [],
        public readonly bool $dryRun = false,
        public readonly array $columns = [],
    ) {
    }

    /**
     * Notes a row the run created, by its table: « Annuler cet import » takes back what nothing uses of it, and only
     * that. A subject notes every row it creates, the record and any row created beside it that is not the record's own
     * (a link to another record, a vendor's code), but never one it only changed.
     */
    public function created(string $table, Uuid $id): void
    {
        $this->created[$table][$id->toRfc4122()] = $id;
    }

    /** @return array<string, list<Uuid>> what the rows created, by table */
    public function createdRecords(): array
    {
        return array_map(array_values(...), $this->created);
    }

    public function ticked(string $switch): bool
    {
        return \in_array($switch, $this->ticked, true);
    }

    /**
     * What `$read` answers, read once in the run under `$key`: the key names what is read and every value it depends on.
     *
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return T
     */
    public function once(string $key, \Closure $read): mixed
    {
        if (!\array_key_exists($key, $this->known)) {
            $this->known[$key] = $read();
        }

        /* @var T */
        return $this->known[$key];
    }
}
