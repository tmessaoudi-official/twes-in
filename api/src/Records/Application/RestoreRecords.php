<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Records\Domain\Exception\RemovalNoLongerPending;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Records\Domain\ValueObject\RemovalKind;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Puts back every record a delete took, as they were, until its day ends; all of them or none. A merge is undone the
 * same way, and every row it pointed at the record kept that still names it is pointed back; undoing one takes the
 * proof merging took.
 */
final readonly class RestoreRecords
{
    public function __construct(
        private RecordStore $store,
        private RecordRemovalRepository $removals,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private ConfirmStepUp $stepUp,
    ) {
    }

    /**
     * @throws UnknownRecords
     * @throws RemovalNoLongerPending
     * @throws RemovalConflict
     * @throws StepUpRequired         when it undoes a merge without a proof given again in the last few minutes
     */
    public function handle(Company $company, Uuid $removalId, ?Uuid $actorUserId): RecordRemoval
    {
        return $this->transactions->run(function () use ($company, $removalId, $actorUserId): RecordRemoval {
            $removal = $this->removals->lockedOfId($company->getId(), $removalId) ?? throw new UnknownRecords('The company has no such delete.');
            $now = $this->clock->now();
            if (!$removal->isRestorableAt($now)) {
                throw new RemovalNoLongerPending('This delete can no longer be undone.');
            }
            $merge = RemovalKind::Merge === $removal->getKind();
            if ($merge && (null === $actorUserId || !$this->stepUp->isRecent($actorUserId))) {
                throw new StepUpRequired('Undoing a merge takes a proof given again in the last few minutes.');
            }
            // The rows first: a refused restore leaves the removal as it was, in memory too. What a merge moved goes back
            // once the records it names stand again.
            $this->store->restore($company->getId(), $removal->getId());
            if ($merge) {
                $this->store->moveBack($company->getId(), $removal->getId());
            }
            $removal->restore($now);
            $this->removals->save($removal);
            foreach ($removal->recordIds() as $id) {
                $this->audit->record(new AuditEntry($removal->getSubject(), $id, $removal->getSubject().($merge ? '.merge_undone' : '.restored'), $actorUserId, [], $company->getId()));
            }

            return $removal;
        });
    }
}
