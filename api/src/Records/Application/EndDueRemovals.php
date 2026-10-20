<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ends every delete whose day is over: it becomes final and its copy goes. Running it again ends nothing twice. A
 * restore never waits on it: the removal refuses one past its day by itself.
 */
final readonly class EndDueRemovals
{
    public const string ENTITY_TYPE = 'record_removal';
    public const string ENDED = 'record_removal.final';

    public function __construct(
        private RecordRemovalRepository $removals,
        private RecordStore $store,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return int how many deletes it ended */
    public function handle(?Uuid $companyId = null): int
    {
        $now = $this->clock->now();
        $ended = 0;
        foreach ($this->removals->due($now, $companyId) as ['company' => $company, 'id' => $id]) {
            $ended += $this->transactions->run(function () use ($company, $id, $now): int {
                $removal = $this->removals->lockedOfId($company, $id);
                if (null === $removal || $removal->getEffectiveAt() > $now) {
                    return 0;
                }
                $removal->end($now);
                $this->store->forget($company, $id);
                $this->removals->save($removal);
                // Also what tells an open « Récemment supprimés » to drop it.
                $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $id, self::ENDED, null, ['subject' => $removal->getSubject(), 'records' => \count($removal->getItems())], $company));

                return 1;
            });
        }

        return $ended;
    }
}
