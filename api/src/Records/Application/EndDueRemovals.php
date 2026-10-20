<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Files\Application\Files;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Shared\Application\Transactions;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ends every delete whose day is over: it becomes final, its copy goes, and the files only the copy named go with it. Running it again ends nothing twice. A
 * restore never waits on it: the removal refuses one past its day by itself.
 */
final readonly class EndDueRemovals
{
    public const string ENTITY_TYPE = 'record_removal';
    public const string ENDED = 'record_removal.final';

    public function __construct(
        private RecordRemovalRepository $removals,
        private RecordStore $store,
        private Files $files,
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
            $keys = $this->transactions->run(function () use ($company, $id, $now): ?array {
                $removal = $this->removals->lockedOfId($company, $id);
                if (null === $removal || $removal->getEffectiveAt() > $now) {
                    return null;
                }
                $removal->end($now);
                $unnamed = $this->store->forget($company, $id);
                $this->removals->save($removal);
                // Also what tells an open « Récemment supprimés » to drop it.
                $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $id, self::ENDED, null, ['subject' => $removal->getSubject(), 'records' => \count($removal->getItems())], $company));

                return $this->files->forget($company, $unnamed);
            });
            if (null !== $keys) {
                // The bytes once their records are gone for good: bytes without a record are only unused.
                $this->files->deleteContents($keys);
                ++$ended;
            }
        }

        return $ended;
    }
}
