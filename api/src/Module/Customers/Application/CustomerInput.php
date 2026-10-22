<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Shared\Domain\ValueObject\CheckMark;
use Symfony\Component\Uid\Uuid;

/** A customer as it is written: its number and profile, and the group, regime and taxes it names by id or code. */
final readonly class CustomerInput
{
    /**
     * @param list<Uuid>              $defaultTaxComponentIds
     * @param array<array-key, mixed> $customFields           values by the company's custom field keys, checked by the use case
     * @param bool                    $identifiersMayFollow   a file's business customer may come without the registration
     *                                                        number the preset requires of one: the law asks for it on the
     *                                                        invoice, where it is refused, and a shop's trade customers
     *                                                        would otherwise be as many refused rows
     * @param CheckMark|null          $mark                   « À vérifier » and its note as written; null keeps them
     */
    public function __construct(
        public string $number,
        public CustomerProfile $profile,
        public ?Uuid $customerGroupId,
        public string $taxRegimeCode,
        public array $defaultTaxComponentIds,
        public bool $isActive,
        public array $customFields = [],
        public bool $identifiersMayFollow = false,
        public ?CheckMark $mark = null,
    ) {
    }

    /** The same input with this profile: its registration numbers as the preset stores them. */
    public function withProfile(CustomerProfile $profile): self
    {
        return clone ($this, ['profile' => $profile]);
    }
}
