<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

use App\Records\Application\NoRecordChosen;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordStore;
use App\Records\Application\RecordSubject;
use App\Records\Application\RemoveRecords;
use App\Records\Application\UnknownRecords;
use App\Tenancy\Domain\Model\Company;

/**
 * What a merge would do to each record, said before anything runs: what moves to the record kept, by table, and what
 * stays naming the record merged away. Counts only, never what the rows hold.
 */
final readonly class PreviewMerge
{
    public function __construct(private RecordCatalogue $catalogue, private RecordStore $store)
    {
    }

    /**
     * @return list<array{id: string, label: string, moves: list<array{table: string, count: int}>, stays: list<array{table: string, count: int}>}>
     *
     * @throws NoRecordChosen
     * @throws UnknownRecords
     */
    public function handle(Company $company, RecordSubject $subject, mixed $ids): array
    {
        $chosen = RemoveRecords::chosen($ids);

        $labels = $this->store->labels($company->getId(), $subject, $chosen);
        if (\count($labels) !== \count($chosen)) {
            throw new UnknownRecords(\sprintf('The company has not every %s asked for.', $subject->table));
        }
        $merging = $this->store->merging($company->getId(), $chosen, $this->catalogue->naming($subject->table));
        $preview = [];
        foreach ($chosen as $id) {
            $key = $id->toRfc4122();
            $preview[] = ['id' => $key, 'label' => $labels[$key], 'moves' => $merging[$key]['moves'], 'stays' => $merging[$key]['stays']];
        }

        return $preview;
    }
}
