<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\ValueObject;

/** Where an order to a vendor stands. */
enum PurchaseOrderStatus: string
{
    /** Still being written: no number, every field revisable. */
    case Draft = 'draft';
    /** Numbered and sent to the vendor: nothing has arrived yet. */
    case Sent = 'sent';
    /** Some of the goods have arrived; the order stays open for the rest. */
    case PartlyReceived = 'partly_received';
    /** Everything ordered has arrived. */
    case Received = 'received';
    /** Part arrived and the rest is no longer expected: the order is settled with what came. */
    case Closed = 'closed';
    /** Withdrawn before anything arrived; a sent order keeps its number. */
    case Cancelled = 'cancelled';

    /** Whether goods may still be received against the order. */
    public function open(): bool
    {
        return self::Sent === $this || self::PartlyReceived === $this;
    }
}
