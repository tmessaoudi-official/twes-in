<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** A registration number a customer may carry: its key, its label, its whole-value shape, and who must carry it. */
final readonly class CustomerIdentifierOption
{
    public function __construct(
        #[Groups([CustomerOptionsResource::READ])]
        public string $key,
        #[Groups([CustomerOptionsResource::READ])]
        public string $label,
        /** A regular expression without delimiters, anchored by the preset. */
        #[Groups([CustomerOptionsResource::READ])]
        public string $pattern,
        /** Whether a business customer billed in the company's own country must carry it. */
        #[Groups([CustomerOptionsResource::READ])]
        public bool $requiredForBusiness,
    ) {
    }
}
