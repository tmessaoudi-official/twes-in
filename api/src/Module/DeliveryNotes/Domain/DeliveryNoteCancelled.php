<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Shared\Domain\DomainEvent;
use Symfony\Component\Uid\Uuid;

/**
 * `delivery_note.cancelled`: a validated note was cancelled before its goods were delivered, so what its validation
 * announced no longer holds. A draft cancelled announced nothing and tells nothing.
 */
final readonly class DeliveryNoteCancelled implements DomainEvent
{
    public function __construct(public Uuid $deliveryNoteId, public Uuid $companyId, public Uuid $establishmentId, public string $number)
    {
    }
}
