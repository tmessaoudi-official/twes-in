<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use Symfony\Component\Uid\Uuid;

/** A delivery note as it is written: its customer and establishment by id (none: the company's default), its header and its lines. */
final readonly class DeliveryNoteInput
{
    /** @param list<DeliveryNoteLineInput> $lines */
    public function __construct(
        public Uuid $customerId,
        public ?Uuid $establishmentId,
        public DeliveryNoteHeader $header,
        public array $lines,
    ) {
    }
}
