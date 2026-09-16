<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\DeliveryNotes;

use App\Module\DeliveryNotes\Domain\DeliveryNoteCancelled;
use App\Module\DeliveryNotes\Domain\DeliveryNoteValidated;
use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Application\TellStockKeepers;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Moves stock when a delivery note is validated or cancelled. The note is already committed, so nothing here throws
 * (docs/SPEC.md § 7, 2026-09-16): a line that moved no stock, or a move that failed, is logged and told to the people
 * who keep the company's stock, and `app:stock:replay-delivery-note` moves a failed note again.
 */
#[AsEventListener(event: DeliveryNoteValidated::class, method: 'onValidated')]
#[AsEventListener(event: DeliveryNoteCancelled::class, method: 'onCancelled')]
final readonly class MoveStockOnDeliveryNotes
{
    public function __construct(private MoveStockForDeliveryNotes $move, private TellStockKeepers $tell, private LoggerInterface $logger)
    {
    }

    public function onValidated(DeliveryNoteValidated $event): void
    {
        try {
            $reasons = $this->move->validated($event->deliveryNoteId, $event->companyId, $event->establishmentId, $event->lines);
        } catch (\Throwable $failure) {
            $this->logger->error('Delivery note {number} was validated, but its stock could not be moved: {failure}.', ['number' => $event->number, 'failure' => $failure->getMessage(), 'exception' => $failure]);
            $this->told(fn () => $this->tell->deliveryNoteMovedNoStock($event->companyId, $event->deliveryNoteId, $event->number));

            return;
        }
        foreach ($reasons as $reason) {
            $this->logger->warning('Delivery note {number} was validated, but {reason}.', ['number' => $event->number, 'reason' => $reason]);
        }
        if ([] !== $reasons) {
            $this->told(fn () => $this->tell->deliveryNoteLeftLinesOut($event->companyId, $event->deliveryNoteId, $event->number));
        }
    }

    public function onCancelled(DeliveryNoteCancelled $event): void
    {
        try {
            $this->move->cancelled($event->deliveryNoteId, $event->companyId);
        } catch (\Throwable $failure) {
            $this->logger->error('Delivery note {number} was cancelled, but its stock could not be returned: {failure}.', ['number' => $event->number, 'failure' => $failure->getMessage(), 'exception' => $failure]);
            $this->told(fn () => $this->tell->deliveryNoteMovedNoStock($event->companyId, $event->deliveryNoteId, $event->number));
        }
    }

    /** Telling is best effort too: a notification that cannot be written leaves the log line as the record. */
    private function told(\Closure $tell): void
    {
        try {
            $tell();
        } catch (\Throwable $failure) {
            $this->logger->error('Stock keepers could not be told: {failure}.', ['failure' => $failure->getMessage(), 'exception' => $failure]);
        }
    }
}
