<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Records\Domain\Exception\RemovalNoLongerPending;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Puts back every record a delete took, as they were, until its day ends; all of them or none. */
final readonly class RestoreRecords
{
    public function __construct(
        private RecordStore $store,
        private RecordRemovalRepository $removals,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws UnknownRecords
     * @throws RemovalNoLongerPending
     * @throws RemovalConflict
     */
    public function handle(Company $company, Uuid $removalId, ?Uuid $actorUserId): RecordRemoval
    {
        return $this->transactions->run(function () use ($company, $removalId, $actorUserId): RecordRemoval {
            $removal = $this->removals->lockedOfId($company->getId(), $removalId) ?? throw new UnknownRecords('The company has no such delete.');
            $now = $this->clock->now();
            if (!$removal->isRestorableAt($now)) {
                throw new RemovalNoLongerPending('This delete can no longer be undone.');
            }
            // The rows first: a refused restore leaves the removal as it was, in memory too.
            $this->store->restore($company->getId(), $removal->getId());
            $removal->restore($now);
            $this->removals->save($removal);
            foreach ($removal->recordIds() as $id) {
                $this->audit->record(new AuditEntry($removal->getSubject(), $id, $removal->getSubject().'.restored', $actorUserId, [], $company->getId()));
            }

            return $removal;
        });
    }
}
