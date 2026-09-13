<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Module\Customers\Domain\CustomerProfile;
use Symfony\Component\Uid\Uuid;

/** A customer as it is written: its number and profile, and the group, regime and taxes it names by id or code. */
final readonly class CustomerInput
{
    /** @param list<Uuid> $defaultTaxComponentIds */
    public function __construct(
        public string $number,
        public CustomerProfile $profile,
        public ?Uuid $customerGroupId,
        public string $taxRegimeCode,
        public array $defaultTaxComponentIds,
        public bool $isActive,
    ) {
    }
}
