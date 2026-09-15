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
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Moves stock when a delivery note is validated or cancelled. The note is already committed: a line that moved no stock
 * is logged, never refused.
 */
#[AsEventListener(event: DeliveryNoteValidated::class, method: 'onValidated')]
#[AsEventListener(event: DeliveryNoteCancelled::class, method: 'onCancelled')]
final readonly class MoveStockOnDeliveryNotes
{
    public function __construct(private MoveStockForDeliveryNotes $move, private LoggerInterface $logger)
    {
    }

    public function onValidated(DeliveryNoteValidated $event): void
    {
        foreach ($this->move->validated($event->deliveryNoteId, $event->companyId, $event->establishmentId, $event->lines) as $reason) {
            $this->logger->warning('Delivery note {number} was validated, but {reason}.', ['number' => $event->number, 'reason' => $reason]);
        }
    }

    public function onCancelled(DeliveryNoteCancelled $event): void
    {
        $this->move->cancelled($event->deliveryNoteId, $event->companyId);
    }
}
