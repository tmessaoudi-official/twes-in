<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Replay;

use App\Module\DeliveryNotes\Domain\Repository\DeliveryNoteRepository;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteStatus;
use App\Module\Inventory\Application\IssuedInvoices;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Application\Replay\StockMoveReplays;
use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\Repository\StockMovementRepository;
use App\Module\Inventory\Domain\ValueObject\FailedMove;
use App\Module\Inventory\Domain\ValueObject\StockMovementKind;
use App\Module\Inventory\Infrastructure\Invoices\MoveStockOnInvoices;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The move a document's validation, cancellation or issue ran, made again from the document as it stands, as the
 * console's `app:stock:replay-*` commands make it: a document whose stock already moved moves nothing more, and a delivery
 * note validated and cancelled since has nothing left to take out.
 */
final readonly class StockReplays implements StockMoveReplays
{
    public function __construct(
        private DeliveryNoteRepository $notes,
        private IssuedInvoices $invoices,
        private StockMovementRepository $movements,
        private MoveStockForDeliveryNotes $move,
        private MoveStockOnInvoices $moveInvoice,
        private LoggerInterface $logger,
    ) {
    }

    public function replay(StockMoveFailure $failure): bool
    {
        $company = $failure->getCompany()->getId();
        $document = $failure->getDocumentId();
        try {
            return match ($failure->getMove()) {
                FailedMove::DeliveryNote, FailedMove::DeliveryNoteCancelled => $this->deliveryNote($failure),
                FailedMove::Invoice, FailedMove::CreditNote => $this->invoice($failure),
            };
        } catch (\Throwable $again) {
            $this->logger->error('The stock of {number} could not be moved again: {failure}.', ['number' => $failure->getNumber(), 'failure' => $again->getMessage(), 'exception' => $again, 'company' => $company->toRfc4122(), 'document' => $document->toRfc4122()]);

            return false;
        }
    }

    private function deliveryNote(StockMoveFailure $failure): bool
    {
        $company = $failure->getCompany()->getId();
        $note = $this->notes->ofIdInCompany($failure->getDocumentId(), $company);
        if (null === $note || DeliveryNoteStatus::Draft === $note->getStatus()) {
            return false;
        }
        $written = $this->written(StockMovement::SOURCE_DELIVERY_NOTE, $note->getId(), $company);
        if (DeliveryNoteStatus::Cancelled === $note->getStatus()) {
            // Cancelled since, or the return was what failed: what was taken out comes back, and nothing taken out is nothing to return.
            $this->move->cancelled($note->getId(), $company);

            return [] === self::of($written, StockMovementKind::Out)
                || [] !== self::of($this->written(StockMovement::SOURCE_DELIVERY_NOTE, $note->getId(), $company), StockMovementKind::In);
        }
        if ([] !== $written) {
            return true;
        }
        $this->move->validated($note->getId(), $company, $note->getEstablishment()->getId(), $note->deliveredQuantities());

        return [] !== $this->written(StockMovement::SOURCE_DELIVERY_NOTE, $note->getId(), $company);
    }

    private function invoice(StockMoveFailure $failure): bool
    {
        $company = $failure->getCompany()->getId();
        $issued = $this->invoices->issued($company, $failure->getDocumentId());
        if (null === $issued) {
            return false;
        }
        $source = FailedMove::CreditNote === $failure->getMove() ? StockMovement::SOURCE_CREDIT_NOTE : StockMovement::SOURCE_INVOICE;
        if ([] !== $this->written($source, $issued->invoiceId, $company)) {
            return true;
        }
        // The listener the issue ran: what it cannot move it logs and records again, as then.
        ($this->moveInvoice)($issued);

        return [] !== $this->written($source, $issued->invoiceId, $company);
    }

    /**
     * Read before and after the move, which writes what the second read finds.
     *
     * @return list<StockMovement>
     *
     * @phpstan-impure
     */
    private function written(string $source, Uuid $documentId, Uuid $companyId): array
    {
        return $this->movements->ofSource($source, $documentId, $companyId);
    }

    /**
     * @param list<StockMovement> $movements
     *
     * @return list<StockMovement>
     */
    private static function of(array $movements, StockMovementKind $kind): array
    {
        return array_values(array_filter($movements, static fn (StockMovement $movement): bool => $kind === $movement->getKind()));
    }
}
