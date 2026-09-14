<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

/** Where a delivery note stands (docs/SPEC.md § 4 delivery_note). */
enum DeliveryNoteStatus: string
{
    /** Still being written: no number, every field revisable. */
    case Draft = 'draft';
    /** Numbered and fixed: the goods may leave. */
    case Validated = 'validated';
    /** The customer has the goods. */
    case Delivered = 'delivered';
    /** Withdrawn; a validated note keeps its number. */
    case Cancelled = 'cancelled';
    /** On an issued invoice: neither delivered nor cancelled from then on. */
    case Invoiced = 'invoiced';
}
