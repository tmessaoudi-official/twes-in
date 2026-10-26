<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Replay;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Domain\Repository\StockMoveFailureRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * A failed stock move settled by a stock keeper from the document's page (workflow hunt, CON-1): moved again, as the
 * console's replay did, or said settled when the stock was set right otherwise. Each is audited as a change of the
 * stock, which the screens showing it hear.
 */
final readonly class SettleStockMoveFailures implements DeclaresAuditActions
{
    public function __construct(
        private StockMoveFailureRepository $failures,
        private StockMoveReplays $replays,
        private AuditTrail $audit,
        private Transactions $transactions,
    ) {
    }

    /** @return list<StockMoveFailure> */
    public function ofDocument(Company $company, Uuid $documentId): array
    {
        return $this->failures->ofDocument($company->getId(), $documentId);
    }

    /** @throws StockMoveFailureNotFound */
    public function find(Company $company, Uuid $failureId): StockMoveFailure
    {
        return $this->failures->ofId($failureId, $company->getId()) ?? throw new StockMoveFailureNotFound();
    }

    /**
     * Moves it again: true when the stock has moved and the failure is settled, false when it failed again and stays.
     *
     * @throws StockMoveFailureNotFound
     */
    public function replay(Company $company, Uuid $failureId, ?Uuid $actorUserId): bool
    {
        $failure = $this->find($company, $failureId);
        // Outside a unit of work: the move runs its own, as when the document was kept.
        if (!$this->replays->replay($failure)) {
            return false;
        }
        $this->settle($failure, StockMoveFailures::REPLAYED, $actorUserId);

        return true;
    }

    /** After the console's replay of a document: what it moved settles the failures recorded for it. */
    public function replayAllOf(Uuid $companyId, Uuid $documentId): void
    {
        foreach ($this->failures->ofDocument($companyId, $documentId) as $failure) {
            if ($this->replays->replay($failure)) {
                $this->settle($failure, StockMoveFailures::REPLAYED, null);
            }
        }
    }

    /**
     * Says it is settled without moving anything: the stock was set right otherwise.
     *
     * @throws StockMoveFailureNotFound
     */
    public function settled(Company $company, Uuid $failureId, ?Uuid $actorUserId): void
    {
        $failure = $this->find($company, $failureId);
        $this->settle($failure, StockMoveFailures::SETTLED, $actorUserId);
    }

    private function settle(StockMoveFailure $failure, string $action, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($failure, $action, $actorUserId): void {
            $this->failures->remove($failure);
            $this->audit->record(new AuditEntry(StockMoveFailures::KIND, $failure->getDocumentId(), $action, $actorUserId, ['move' => $failure->getMove()->value, 'number' => $failure->getNumber()], $failure->getCompany()->getId()));
        });
    }

    public function auditActions(): array
    {
        return [
            StockMoveFailures::REPLAYED => AuditScope::Company,
            StockMoveFailures::SETTLED => AuditScope::Company,
        ];
    }
}
