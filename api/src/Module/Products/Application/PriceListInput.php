<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

use Symfony\Component\Uid\Uuid;

/** What a price list is written with: its own fields, and the prices it holds when a save gives them. */
final readonly class PriceListInput
{
    /**
     * @param list<PriceListItemInput>|null $items null leaves the prices as they are
     */
    public function __construct(
        public string $name,
        public ?Uuid $customerGroupId,
        public ?Uuid $customerId,
        public ?\DateTimeImmutable $validFrom,
        public ?\DateTimeImmutable $validTo,
        public bool $isActive,
        public ?array $items,
    ) {
    }
}
