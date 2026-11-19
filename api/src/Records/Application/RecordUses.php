<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use Symfony\Component\Uid\Uuid;

/**
 * What names each record so that its delete is refused: the one count a delete makes before it goes, and a screen reads
 * before offering one. It locks nothing; a delete locks the records first and asks again.
 */
final readonly class RecordUses
{
    public function __construct(private RecordCatalogue $catalogue, private RecordStore $store)
    {
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, non-empty-list<array{table: string, column: string, count: int}>> by record id, those named
     *                                                                                         by nothing left out
     */
    public function of(Uuid $companyId, string $subject, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $using = array_values(array_filter($this->catalogue->naming($subject), static fn (RecordReference $reference): bool => RecordUse::Uses === $reference->onDelete));

        return $this->store->uses($companyId, $ids, $using);
    }
}
