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
use App\Records\Application\Merge\MergeAlias;
use App\Records\Application\Merge\MergeEffects;
use App\Records\Application\Merge\MergeRefused;
use App\Records\Application\Merge\MergeTransacted;
use App\Records\Domain\Exception\RemovalNoLongerPending;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Records\Domain\ValueObject\RemovalKind;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Puts back every record a delete took, as they were, until its day ends; all of them or none, with what the context
 * that made the removal puts back beside them (an import's undo, the stock it took back). A merge is undone the
 * same way, and every row it pointed at the record kept that still names it is pointed back; undoing one takes the
 * proof merging took, and is refused once the record kept traded since (goods received, an expense in the books).
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
        private RecordCatalogue $catalogue,
        private LiveChanges $liveChanges,
        /** @var iterable<MergeEffects> */
        #[AutowireIterator('app.record.merge_effects')]
        private iterable $effects = [],
        /** @var iterable<RemovalRestored> */
        #[AutowireIterator('app.record.removal_restored')]
        private iterable $restored = [],
    ) {
    }

    /**
     * @throws UnknownRecords
     * @throws RemovalNoLongerPending
     * @throws RemovalConflict
     * @throws StepUpRequired         when it undoes a merge without a proof given again in the last few minutes
     * @throws MergeAlias             when the record a merge kept was merged into another since
     * @throws MergeTransacted        when the record a merge kept traded since
     * @throws MergeRefused           when a module refuses to undo what it did for the merge
     */
    public function handle(Company $company, Uuid $removalId, ?Uuid $actorUserId): RecordRemoval
    {
        return $this->transactions->run(function () use ($company, $removalId, $actorUserId): RecordRemoval {
            $removal = $this->removals->lockedOfId($company->getId(), $removalId) ?? throw new UnknownRecords('The company has no such delete.');
            $now = $this->clock->now();
            if (!$removal->isRestorableAt($now)) {
                throw $removal->noLongerPending();
            }
            $merge = RemovalKind::Merge === $removal->getKind();
            if ($merge && (null === $actorUserId || !$this->stepUp->isRecent($actorUserId))) {
                throw new StepUpRequired('Undoing a merge takes a proof given again in the last few minutes.');
            }
            $naming = $this->catalogue->naming($removal->getSubject());
            $kept = $removal->kept();
            if ($merge && null !== $kept) {
                // Merged into another since, the record kept holds what this merge gave it under that one: that merge is
                // undone first, or this one would hand back what is no longer the record kept's.
                $subject = $this->catalogue->subject($removal->getSubject());
                if (MergedAway::Aliased === $subject->mergedAway && [] !== $this->store->mergedAwayAmong($company->getId(), $subject, [Uuid::fromString($kept['id'])])) {
                    throw new MergeAlias(\sprintf('The %s this merge kept was merged into another since: that merge is undone first.', $removal->getSubject()));
                }
                $traded = $this->store->tradedSince($company->getId(), Uuid::fromString($kept['id']), $naming, $removal->getRemovedAt());
                if ([] !== $traded) {
                    throw new MergeTransacted($traded);
                }
            }
            // The rows first: a refused restore leaves the removal as it was, in memory too. What a merge moved goes back
            // once the records it names stand again.
            $this->store->restore($company->getId(), $removal->getId());
            if ($merge) {
                $this->store->moveBack($company->getId(), $removal->getId(), $naming);
                $merged = $removal->recordIds();
                if (null !== $kept && [] !== $merged) {
                    foreach ($this->effects as $effects) {
                        if ($effects->subject() === $removal->getSubject()) {
                            $effects->unmerging($company->getId(), $removal->getId(), Uuid::fromString($kept['id']), $merged, $actorUserId, $now);
                        }
                    }
                }
            }
            foreach ($this->restored as $restored) {
                $restored->restored($company, $removal, $actorUserId);
            }
            $removal->restore($now);
            $this->removals->save($removal);
            foreach ($removal->recordIds() as $id) {
                $this->audit->record(new AuditEntry($removal->getSubject(), $id, $removal->getSubject().($merge ? '.merge_undone' : '.restored'), $actorUserId, [], $company->getId()));
            }
            if ($merge) {
                foreach (array_unique(array_map(static fn (RecordReference $reference): string => $reference->table, array_filter($naming, static fn (RecordReference $reference): bool => \in_array($reference->onMerge, [RecordMerge::Moves, RecordMerge::MovesOrDrops], true)))) as $table) {
                    if ($table !== $removal->getSubject()) {
                        $this->liveChanges->stage(new LiveChange($table, null, $removal->getSubject().'.merge_undone', $actorUserId, $company->getId()));
                    }
                }
            }

            return $removal;
        });
    }
}
