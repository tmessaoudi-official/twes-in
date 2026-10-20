<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** « Récemment supprimés »: the deletes still undoable, of the subjects the person may write. */
final readonly class ReadRemovals
{
    public function __construct(private RecordRemovalRepository $removals, private ClockInterface $clock)
    {
    }

    /**
     * @param list<string> $subjects
     *
     * @return list<RecordRemoval>
     */
    public function pending(Company $company, array $subjects): array
    {
        if ([] === $subjects) {
            return [];
        }
        $now = $this->clock->now();

        // One whose day ended while the worker had not yet passed is no longer offered.
        return array_values(array_filter($this->removals->pendingOf($company->getId(), $subjects), static fn (RecordRemoval $removal): bool => $removal->getEffectiveAt() > $now));
    }

    /** @throws UnknownRecords */
    public function one(Company $company, Uuid $id): RecordRemoval
    {
        return $this->removals->ofId($company->getId(), $id) ?? throw new UnknownRecords('The company has no such delete.');
    }
}
