<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What a delivery notes list asks for (docs/SPEC.md § 7, lists at scale): words found in the number, the customer's
 * own reference or the customer as the note recorded them, whatever their case and accents (under three characters,
 * the exact number only), a status, one customer, and the order.
 *
 * The searchable text is the note's own, so that one trigram index answers it. A DRAFT is therefore searchable by the
 * customer's reference but not by the customer's name: the name is copied onto the note when it is validated. Pick the
 * customer to narrow a draft list — that is what `customer` is for.
 */
final readonly class DeliveryNoteSearch
{
    public const array SORTS = ['number', 'customer', 'issueDate', 'deliveryDate', 'status'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?string $text = null,
        public ?DeliveryNoteStatus $status = null,
        public ?Uuid $customer = null,
        public array $order = [],
    ) {
    }
}
