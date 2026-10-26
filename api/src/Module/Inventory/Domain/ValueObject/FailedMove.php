<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain\ValueObject;

/**
 * Which stock move of a document failed after the document itself was kept: a delivery note's goods taken out when it
 * was validated or put back when it was cancelled, an invoice's taken out when it was issued, a credit note's returned.
 */
enum FailedMove: string
{
    case DeliveryNote = 'delivery_note';
    case DeliveryNoteCancelled = 'delivery_note_cancelled';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
}
