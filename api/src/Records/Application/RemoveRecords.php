<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Records\Application\Merge\MergeAlias;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\Repository\RecordRemovalRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Deletes records nothing names, with the rows that belong to them, all of them or none: one a row still names, through a foreign key or not, refuses the
 * whole delete and says what names it, before anything is deleted, so that no foreign key emptying its column on delete
 * lets it through. The records are locked first, so nothing comes to name one between the count and the delete, except
 * a row naming it with no foreign key, written meanwhile by another request that never reads the record itself.
 */
final readonly class RemoveRecords implements DeclaresAuditActions
{
    public function __construct(
        private RecordCatalogue $catalogue,
        private RecordStore $store,
        private RecordRemovalRepository $removals,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.records.undoable_for')]
        private string $undoableFor,
    ) {
    }

    /**
     * @throws NoRecordChosen
     * @throws UnknownRecords
     * @throws MergeAlias
     * @throws RecordsInUse
     */
    public function handle(Company $company, RecordSubject $subject, mixed $ids, ?Uuid $actorUserId): RecordRemoval
    {
        $chosen = self::chosen($ids);

        return $this->transactions->run(function () use ($company, $subject, $chosen, $actorUserId): RecordRemoval {
            $labels = $this->store->lockedLabels($company->getId(), $subject, $chosen);
            if (\count($labels) !== \count($chosen)) {
                throw new UnknownRecords(\sprintf('The company has not every %s asked for.', $subject->table));
            }
            // An alias stands for what the books and the stock still name: it goes only with the merge's undo.
            if (MergedAway::Aliased === $subject->mergedAway && [] !== $this->store->mergedAwayAmong($company->getId(), $subject, $chosen)) {
                throw new MergeAlias(\sprintf('A %s merged into another is not deleted.', $subject->table));
            }
            $naming = $this->catalogue->naming($subject->table);
            foreach ($naming as $reference) {
                if (RecordUse::Cleared === $reference->onDelete) {
                    // A column emptied by the delete would need its value copied and written back, which no subject asks yet.
                    throw new \LogicException(\sprintf('%s.%s is %s, which deleting a %s does not handle yet.', $reference->table, $reference->column, $reference->onDelete->value, $subject->table));
                }
            }
            $used = $this->store->uses($company->getId(), $chosen, self::of($naming, RecordUse::Uses));
            if ([] !== $used) {
                $records = [];
                foreach ($chosen as $id) {
                    $uses = $used[$id->toRfc4122()] ?? null;
                    if (null !== $uses) {
                        $records[] = ['id' => $id->toRfc4122(), 'label' => $labels[$id->toRfc4122()], 'uses' => $uses];
                    }
                }
                \assert([] !== $records);

                throw new RecordsInUse($records);
            }

            $items = array_map(static fn (Uuid $id): array => ['id' => $id->toRfc4122(), 'label' => $labels[$id->toRfc4122()]], $chosen);
            // The record first: every row copied names it.
            $removal = new RecordRemoval(Uuid::v7(), $company, $subject->table, $items, $actorUserId, $this->clock->now(), new \DateInterval($this->undoableFor));
            $this->removals->save($removal);
            $this->store->remove($company->getId(), $removal->getId(), $subject, $chosen, self::of($naming, RecordUse::Owned));
            foreach ($chosen as $id) {
                $this->audit->record(new AuditEntry($subject->table, $id, $subject->table.'.deleted', $actorUserId, [], $company->getId()));
            }

            return $removal;
        });
    }

    /**
     * Takes back the records of one subject an import created, all those nothing uses and none of the others: the rows
     * that import wrote naming one (the goods it brought) belong to it for this, and go with it. What a record kept names
     * is kept too, a category under which a kept one sits. One deleted or merged away since stays as it is. Runs inside the undo's transaction, which locks the records first.
     *
     * @param list<Uuid> $ids
     */
    public function removeImported(Company $company, RecordSubject $subject, array $ids, Uuid $runId, ?Uuid $actorUserId): ImportedRecordsTaken
    {
        return $this->transactions->run(function () use ($company, $subject, $ids, $runId, $actorUserId): ImportedRecordsTaken {
            $labels = [] === $ids ? [] : $this->store->lockedLabels($company->getId(), $subject, $ids);
            $present = array_values(array_filter($ids, static fn (Uuid $id): bool => isset($labels[$id->toRfc4122()])));
            if ([] === $present) {
                return new ImportedRecordsTaken(null, [], []);
            }
            $naming = $this->catalogue->naming($subject->table);
            foreach ($naming as $reference) {
                if (RecordUse::Cleared === $reference->onDelete) {
                    throw new \LogicException(\sprintf('%s.%s is %s, which deleting a %s does not handle yet.', $reference->table, $reference->column, $reference->onDelete->value, $subject->table));
                }
            }
            $byImport = array_map(static fn (RecordReference $reference): RecordReference => $reference->rowsOfImport($runId->toRfc4122()), array_values(array_filter($naming, static fn (RecordReference $reference): bool => null !== $reference->importRunColumn)));
            // A record kept names others as any row does: what it names is kept too, until nothing more is. The rows among
            // those still going are left out of each count, since they go with them.
            $using = [];
            $going = $present;
            do {
                $used = $this->store->uses($company->getId(), $going, self::of($naming, RecordUse::Uses));
                $own = [] === $byImport ? [] : $this->store->uses($company->getId(), $going, $byImport);
                $merged = MergedAway::Aliased === $subject->mergedAway ? $this->store->mergedAwayAmong($company->getId(), $subject, $going) : [];
                $found = [];
                foreach ($going as $id) {
                    $key = $id->toRfc4122();
                    $uses = self::lessOwn($used[$key] ?? [], $own[$key] ?? []);
                    // An alias stands for what the books and the stock still name: it goes only with the merge's undo.
                    if (\in_array($key, $merged, true)) {
                        $uses[] = ['table' => $subject->table, 'column' => 'merged_into_id', 'count' => 1];
                    }
                    if ([] !== $uses) {
                        $found[$key] = $uses;
                    }
                }
                $using += $found;
                $going = array_values(array_filter($going, static fn (Uuid $id): bool => !isset($found[$id->toRfc4122()])));
            } while ([] !== $found && [] !== $going);

            $removable = $removed = $kept = [];
            foreach ($present as $id) {
                $key = $id->toRfc4122();
                if (isset($using[$key])) {
                    $kept[] = ['id' => $key, 'label' => $labels[$key], 'uses' => $using[$key]];
                    continue;
                }
                $removable[] = $id;
                $removed[] = ['id' => $key, 'label' => $labels[$key]];
            }
            if ([] === $removed) {
                return new ImportedRecordsTaken(null, [], $kept);
            }

            $removal = RecordRemoval::import(Uuid::v7(), $company, $subject->table, $removed, $actorUserId, $this->clock->now(), new \DateInterval($this->undoableFor));
            $this->removals->save($removal);
            $this->store->remove($company->getId(), $removal->getId(), $subject, $removable, [...self::of($naming, RecordUse::Owned), ...$byImport]);
            foreach ($removable as $id) {
                $this->audit->record(new AuditEntry($subject->table, $id, $subject->table.'.deleted', $actorUserId, [], $company->getId()));
            }

            return new ImportedRecordsTaken($removal, $removed, $kept);
        });
    }

    /**
     * What names a record once the rows its own import wrote are left out.
     *
     * @param list<array{table: string, column: string, count: int}> $uses
     * @param list<array{table: string, column: string, count: int}> $own
     *
     * @return list<array{table: string, column: string, count: int}>
     */
    private static function lessOwn(array $uses, array $own): array
    {
        $left = [];
        foreach ($uses as $use) {
            foreach ($own as $mine) {
                if ($mine['table'] === $use['table'] && $mine['column'] === $use['column']) {
                    $use['count'] -= $mine['count'];
                }
            }
            if ($use['count'] > 0) {
                $left[] = $use;
            }
        }

        return $left;
    }

    /**
     * @param list<RecordReference> $references
     *
     * @return list<RecordReference>
     */
    private static function of(array $references, RecordUse $use): array
    {
        return array_values(array_filter($references, static fn (RecordReference $reference): bool => $use === $reference->onDelete));
    }

    /**
     * The records a request names, each once, read the same way by a delete and a merge.
     *
     * @return non-empty-list<Uuid>
     *
     * @throws NoRecordChosen
     * @throws UnknownRecords
     */
    public static function chosen(mixed $ids): array
    {
        if (!\is_array($ids) || [] === $ids || !array_is_list($ids)) {
            throw new NoRecordChosen('Choose at least one record to delete.');
        }
        $chosen = [];
        foreach ($ids as $id) {
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new UnknownRecords('A record is named by its id.');
            }
            $chosen[Uuid::fromString($id)->toRfc4122()] = Uuid::fromString($id);
        }

        return array_values($chosen);
    }

    /** Each record's delete, by the table its subject names. */
    public function auditActions(): array
    {
        $actions = [];
        foreach ($this->catalogue->subjects() as $subject) {
            $actions[$subject->table.'.deleted'] = new Recorded($subject->table, AuditScope::Company);
        }

        return $actions;
    }
}
