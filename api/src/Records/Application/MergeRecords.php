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
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Merges records of one subject into the one kept, all of them or none: every row a reference moves comes to name the
 * record kept (its twin dropped where the one kept has one), and what is merged away goes, or stays as an alias, as
 * its subject says. It takes a proof given again in the last few minutes, as a change of many links at once, and is
 * kept a day on « Récemment supprimés » for « Annuler la fusion ».
 */
final readonly class MergeRecords
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
        #[Autowire(param: 'app.records.undoable_for')]
        private string $undoableFor,
    ) {
    }

    /**
     * @throws NoRecordChosen
     * @throws UnknownRecords
     * @throws MergeIntoItself
     * @throws MergeCycle
     * @throws MergeConflict
     * @throws StepUpRequired
     */
    public function handle(Company $company, RecordSubject $subject, mixed $keptId, mixed $ids, Uuid $actorUserId): RecordRemoval
    {
        $kept = RemoveRecords::chosen([$keptId])[0];
        $merged = RemoveRecords::chosen($ids);
        if (\in_array($kept->toRfc4122(), array_map(static fn (Uuid $id): string => $id->toRfc4122(), $merged), true)) {
            throw new MergeIntoItself('The record kept is among those merged into it.');
        }
        if (!$this->stepUp->isRecent($actorUserId)) {
            throw new StepUpRequired('Merging takes a proof given again in the last few minutes.');
        }
        if (MergedAway::Aliased === $subject->mergedAway) {
            // The alias, its switch and the pickers that leave it out come with the first subject merged that way.
            throw new \LogicException(\sprintf('%s is merged as an alias, which the records engine does not do yet.', $subject->table));
        }

        return $this->transactions->run(function () use ($company, $subject, $kept, $merged, $actorUserId): RecordRemoval {
            $all = [$kept, ...$merged];
            $labels = $this->store->lockedLabels($company->getId(), $subject, $all);
            if (\count($labels) !== \count($all)) {
                throw new UnknownRecords(\sprintf('The company has not every %s asked for.', $subject->table));
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
            // One at a time, so that a row two of them both hold is moved once and dropped as a twin the second time.
            foreach ($merged as $id) {
                $this->store->moveTo($company->getId(), $merge->getId(), $kept, $id, $moving);
            }
            // Nothing moving names them now; whatever of theirs did not move goes with them, copied for the undo.
            $this->store->remove($company->getId(), $merge->getId(), $subject, $merged, $owned);
            foreach ($merged as $id) {
                $this->audit->record(new AuditEntry($subject->table, $id, \sprintf(self::MERGED, $subject->table), $actorUserId, [], $company->getId()));
            }

            return $merge;
        });
    }
}
