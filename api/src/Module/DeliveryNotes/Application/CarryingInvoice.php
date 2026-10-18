<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use Symfony\Component\Uid\Uuid;

/**
 * An invoice that is not cancelled carrying lines of notes: its id, its number once it has one, its status as the invoices
 * name it, and which of the lines asked about it carries.
 */
final readonly class CarryingInvoice
{
    /** @param list<string> $deliveryNoteLineIds */
    public function __construct(public Uuid $id, public ?string $number, public string $status, public array $deliveryNoteLineIds)
    {
    }
}
