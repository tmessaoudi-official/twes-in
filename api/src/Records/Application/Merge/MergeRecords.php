<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application\Merge;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Records\Application\MergedAway;
use App\Records\Application\NoRecordChosen;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RecordMerge;
use App\Records\Application\RecordReference;
use App\Records\Application\RecordStore;
use App\Records\Application\RecordSubject;
use App\Records\Application\RecordUse;
use App\Records\Application\RemoveRecords;
use App\Records\Application\UnknownRecords;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * Merges records of one subject into the one kept, all of them or none: every row a reference moves comes to name the
 * record kept (its twin dropped where the one kept has one), and what is merged away goes, or stays as an alias, as
 * its subject says. It takes a proof given again in the last few minutes, as a change of many links at once, and is
 * kept a day on « Récemment supprimés » for « Annuler la fusion ».
 */
final readonly class MergeRecords implements DeclaresAuditActions
{
    public const string MERGED = '%s.merged';

    public function __construct(
        private RecordCatalogue $catalogue,
        private RecordStore $store,
        private RecordRemovalRepository $removals,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        private ConfirmStepUp $stepUp,
        private LiveChanges $liveChanges,
        #[Autowire(param: 'app.records.undoable_for')]
        private string $undoableFor,
        /** @var iterable<MergeEffects> */
        #[AutowireIterator('app.record.merge_effects')]
        private iterable $effects = [],
    ) {
    }

    /**
     * @throws NoRecordChosen
     * @throws UnknownRecords
     * @throws MergeIntoItself
     * @throws MergeCycle
     * @throws MergeConflict
     * @throws MergeAlias
     * @throws MergeMismatch
     * @throws MergeRefused
     * @throws StepUpRequired
     */
    public function handle(Company $company, RecordSubject $subject, mixed $keptId, mixed $ids, MergeActor $actor): RecordRemoval
    {
        $actorUserId = $actor->id;
        if (null === $subject->mergedAway) {
            throw new UnknownRecords(\sprintf('A %s is not merged.', $subject->table));
        }
        $kept = RemoveRecords::chosen([$keptId])[0];
        $merged = RemoveRecords::chosen($ids);
        if (\in_array($kept->toRfc4122(), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $merged), true)) {
            throw new MergeIntoItself('The record kept is among those merged into it.');
        }
        if (!$this->stepUp->isRecent($actorUserId)) {
            throw new StepUpRequired('Merging takes a proof given again in the last few minutes.');
        }

        return $this->transactions->run(function () use ($company, $subject, $kept, $merged, $actor, $actorUserId): RecordRemoval {
            $all = [$kept, ...$merged];
            $labels = $this->store->lockedLabels($company->getId(), $subject, $all);
            if (\count($labels) !== \count($all)) {
                throw new UnknownRecords(\sprintf('The company has not every %s asked for.', $subject->table));
            }
            $aliased = MergedAway::Aliased === $subject->mergedAway;
            // Before the cycle: an alias points at the record it was merged into, and is refused for being one.
            if ($aliased && [] !== $this->store->mergedAwayAmong($company->getId(), $subject, $all)) {
                throw new MergeAlias(\sprintf('A %s merged into another is neither merged again nor merged into.', $subject->table));
            }
            $disagreeing = $this->store->disagreeing($company->getId(), $subject, $all);
            if (null !== $disagreeing) {
                throw new MergeMismatch($disagreeing);
            }
            $naming = $this->catalogue->naming($subject->table);
            foreach ($naming as $reference) {
                if (RecordUse::Cleared === $reference->onDelete) {
                    throw new \LogicException(\sprintf('%s.%s is %s, which merging a %s does not handle yet.', $reference->table, $reference->column, $reference->onDelete->value, $subject->table));
                }
                if ($reference->table === $subject->table && \in_array($reference->onMerge, [RecordMerge::Moves, RecordMerge::MovesOrDrops], true) && $this->store->descendsFrom($company->getId(), $kept, $merged, $reference)) {
                    throw new MergeCycle(\sprintf('The %s kept sits under one merged into it.', $subject->table));
                }
            }
            $moving = array_values(array_filter($naming, static fn (RecordReference $reference): bool => \in_array($reference->onMerge, [RecordMerge::Moves, RecordMerge::MovesOrDrops], true)));
            $owned = array_values(array_filter($naming, static fn (RecordReference $reference): bool => RecordUse::Owned === $reference->onDelete));

            $merge = RecordRemoval::merge(
                Uuid::v7(),
                $company,
                $subject->table,
                ['id' => $kept->toRfc4122(), 'label' => $labels[$kept->toRfc4122()]],
                array_map(static fn (Uuid $id): array => ['id' => $id->toRfc4122(), 'label' => $labels[$id->toRfc4122()]], $merged),
                $actorUserId,
                $this->clock->now(),
                new \DateInterval($this->undoableFor),
            );
            $this->removals->save($merge);
            // Before anything is pointed elsewhere, while what is merged away still stands as itself.
            foreach ($this->effects as $effects) {
                if ($effects->subject() === $subject->table) {
                    $effects->merging($company->getId(), $merge->getId(), $kept, $merged, $actor, $this->clock->now());
                }
            }
            // One at a time, so that a row two of them both hold is moved once and dropped as a twin the second time.
            foreach ($merged as $id) {
                $this->store->moveTo($company->getId(), $merge->getId(), $kept, $id, $moving);
            }
            if ($aliased) {
                // They stay for what still names them, switched off and pointing at the one kept.
                $this->store->alias($company->getId(), $merge->getId(), $subject, $kept, $merged);
            } else {
                // Nothing moving names them now; whatever of theirs did not move goes with them, copied for the undo.
                $this->store->remove($company->getId(), $merge->getId(), $subject, $merged, $owned);
            }
            foreach ($merged as $id) {
                $this->audit->record(new AuditEntry($subject->table, $id, \sprintf(self::MERGED, $subject->table), $actorUserId, [], $company->getId()));
            }
            // The audit tells the subject's screens; those listing what moved hear it here.
            foreach (array_unique(array_map(static fn (RecordReference $reference): string => $reference->table, $moving)) as $table) {
                if ($table !== $subject->table) {
                    $this->liveChanges->stage(new LiveChange($table, null, \sprintf(self::MERGED, $subject->table), $actorUserId, $company->getId()));
                }
            }

            return $merge;
        });
    }

    /** Each merge, for a subject that merges. */
    public function auditActions(): array
    {
        $actions = [];
        foreach ($this->catalogue->subjects() as $subject) {
            if (null !== $subject->mergedAway) {
                $actions[\sprintf(self::MERGED, $subject->table)] = AuditScope::Company;
            }
        }

        return $actions;
    }
}
