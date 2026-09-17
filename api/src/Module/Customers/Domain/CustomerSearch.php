<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * What a customers list asks for: words found in the number, name, legal name, email or billing city, whatever their
 * case and accents, the choices that narrow it, and the order, always ending on the number so a page never shifts.
 */
final readonly class CustomerSearch
{
    public const array SORTS = ['number', 'name', 'kind', 'customerGroup', 'city', 'isActive'];

    /** @param array<string, 'asc'|'desc'> $order one of SORTS per key, in the order it applies */
    public function __construct(
        public ?string $text = null,
        public ?CustomerKind $kind = null,
        public ?Uuid $groupId = null,
        public ?bool $active = null,
        public array $order = [],
    ) {
    }
}
