<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use App\Shared\Domain\DomainEvent;
use Symfony\Component\Uid\Uuid;

/** `delivery_note.validated`: a note was numbered and its goods leave the establishment (docs/SPEC.md § 5 G6). */
final readonly class DeliveryNoteValidated implements DomainEvent
{
    /** @param list<DeliveredQuantity> $lines in the note's order */
    public function __construct(
        public Uuid $deliveryNoteId,
        public Uuid $companyId,
        public Uuid $establishmentId,
        public string $number,
        public \DateTimeImmutable $issueDate,
        public array $lines,
    ) {
    }
}
