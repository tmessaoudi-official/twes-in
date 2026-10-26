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
use App\ImportExport\Application\RunImport;
use App\ImportExport\Domain\Repository\ImportRunRepository;
use App\Records\Application\RemovalConflict;
use App\Records\Application\RemovalRestored;
use App\Records\Domain\Model\RecordRemoval;
use App\Records\Domain\ValueObject\RemovalKind;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The bin restores an import's undo: the stock that undo took back comes back with the records, and the import stands
 * again, to be taken back again within its day. Goods counted or gone out since stand in the stock's way, and refuse
 * the whole restore.
 */
final readonly class RestoreUndoneImport implements RemovalRestored, DeclaresAuditActions
{
    public function __construct(
        private ImportRunRepository $runs,
        private ImportedStock $stock,
        private AuditTrail $audit,
        private Transactions $transactions,
    ) {
    }

    public function restored(Company $company, RecordRemoval $removal, ?Uuid $actorUserId): void
    {
        if (RemovalKind::Import !== $removal->getKind()) {
            return;
        }
        // Inside the restore's own transaction, which this joins.
        $this->transactions->run(function () use ($company, $removal, $actorUserId): void {
            $found = $this->runs->ofUndoRemoval($company->getId(), $removal->getId())
                ?? throw new \LogicException('An import\'s undo made this delete, and keeps its id.');
            $run = $this->runs->lockedOfId($company->getId(), $found->getId())
                ?? throw new \LogicException('The import found a moment ago is there.');
            $undoId = $run->getUndoId() ?? throw new \LogicException('An import undone keeps the id of its undo.');
            try {
                $this->stock->putBack($company, $run->getId(), $undoId, $actorUserId);
            } catch (ImportNotRestored $refused) {
                throw new RemovalConflict('stock_movement', $refused);
            }
            $run->restored();
            $this->runs->save($run);
            $this->audit->record(new AuditEntry(RunImport::RUN_KIND, $run->getId(), 'import.restored', $actorUserId, [], $company->getId()));
        });
    }

    public function auditActions(): array
    {
        return [
            'import.restored' => new Recorded(RunImport::RUN_KIND, AuditScope::Company),
        ];
    }
}
