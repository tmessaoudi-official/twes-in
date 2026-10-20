<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
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
final readonly class RemoveRecords
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
}
