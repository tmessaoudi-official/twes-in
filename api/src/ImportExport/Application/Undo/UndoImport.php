<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpRequired;
use App\ImportExport\Application\RunImport;
use App\ImportExport\Domain\Repository\ImportRunRepository;
use App\Records\Application\RecordCatalogue;
use App\Records\Application\RemoveRecords;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\Uid\Uuid;

/**
 * « Annuler cet import », within the day a delete may be undone: what the file created and nothing used since goes to
 * « Récemment supprimés », as one delete the bin puts back whole; what something uses stays, and is said; the stock it
 * brought is taken back by movements of its own. What it only changed stays: the file held no earlier value to put
 * back. It takes the proof given again a few minutes ago, as the import of what is sensitive does, and runs once.
 *
 * The caller has checked the person may write the import's subject, in a company whose module is on.
 */
final readonly class UndoImport implements DeclaresAuditActions
{
    /** @param iterable<UndoesImportedRows> $rows */
    public function __construct(
        private ImportRunRepository $runs,
        private RecordCatalogue $records,
        private RemoveRecords $remove,
        private ImportedStock $stock,
        private ConfirmStepUp $stepUp,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
        #[Autowire(param: 'app.records.undoable_for')]
        private string $undoableFor,
        #[AutowireIterator('app.import.undoes_rows')]
        private iterable $rows,
    ) {
    }

    /**
     * @throws StepUpRequired    without a proof given again in the last few minutes
     * @throws UnknownImportRun  when the company has no such import
     * @throws ImportNotUndoable when it was taken back already, or its day is over
     */
    public function handle(Company $company, Uuid $runId, ?Uuid $actorUserId): ImportUndone
    {
        if (null === $actorUserId || !$this->stepUp->isRecent($actorUserId)) {
            throw new StepUpRequired('Undoing an import takes a proof given again in the last few minutes.');
        }

        return $this->transactions->run(function () use ($company, $runId, $actorUserId): ImportUndone {
            $run = $this->runs->lockedOfId($company->getId(), $runId) ?? throw new UnknownImportRun('The company has no such import.');
            $now = $this->clock->now();
            if (null !== $run->getUndoneAt()) {
                throw new ImportNotUndoable(ImportNotUndoable::UNDONE, 'This import was taken back already.');
            }
            if (!$run->isUndoableAt($now, new \DateInterval($this->undoableFor))) {
                throw new ImportNotUndoable(ImportNotUndoable::FINAL, 'This import can no longer be taken back: its day is over.');
            }

            $created = $this->runs->createdBy($company->getId(), $runId);
            $subjects = $this->records->subjects();
            $rowsKept = [];
            // The rows no person deletes first: a link to a vendor goes before the product it links could.
            foreach ($this->rows as $handler) {
                $mine = array_intersect_key($created, array_flip($handler->tables()));
                if ([] !== $mine) {
                    array_push($rowsKept, ...$handler->undo($company, $mine, $actorUserId));
                }
                $created = array_diff_key($created, $mine);
            }
            $removal = null;
            $removed = $kept = [];
            foreach ($created as $table => $ids) {
                $subject = $subjects[$table] ?? throw new \LogicException(\sprintf('An import created rows of %s, which nothing takes back.', $table));
                $taken = $this->remove->removeImported($company, $subject, $ids, $runId, $actorUserId);
                if (null !== $taken->removal) {
                    if (null !== $removal) {
                        throw new \LogicException('An import creates the records of its one subject, which go to the bin as one delete.');
                    }
                    $removal = $taken->removal;
                }
                array_push($removed, ...$taken->removed);
                array_push($kept, ...$taken->kept);
            }
            // The stock last: the records gone took their own movements with them, whatever is left is taken back.
            $undoId = Uuid::v7();
            $stock = $this->stock->takeBack($company, $runId, $undoId, $actorUserId);

            $run->undo($undoId, $actorUserId, $now, $removal?->getId());
            $this->runs->save($run);
            $this->audit->record(new AuditEntry(RunImport::RUN_KIND, $runId, 'import.undone', $actorUserId, [], $company->getId()));

            return new ImportUndone($removal?->getId(), $removed, $kept, $rowsKept, $stock['takenBack'], $stock['kept'], $run->getUpdated());
        });
    }

    public function auditActions(): array
    {
        return [
            'import.undone' => new Recorded(RunImport::RUN_KIND, AuditScope::Company),
        ];
    }
}
