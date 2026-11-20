<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Records\Application\Links\ConfigurationLinks;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What names each record so that its delete is refused: the one count a delete makes before it goes, and a screen reads
 * before offering one. A link that only sets something up names it too, unless the company has its deletes empty those
 * (ConfigurationLinks). It locks nothing; a delete locks the records first and asks again.
 */
final readonly class RecordUses
{
    public function __construct(private RecordCatalogue $catalogue, private RecordStore $store, private ConfigurationLinks $links)
    {
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return array<string, non-empty-list<array{table: string, column: string, count: int}>> by record id, those named
     *                                                                                         by nothing left out
     */
    public function of(Company $company, string $subject, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }
        $using = $this->links->using($company, $this->catalogue->naming($subject));

        return $this->store->uses($company->getId(), $ids, $using);
    }
}
