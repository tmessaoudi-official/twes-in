<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use Symfony\Component\Serializer\Attribute\Groups;

/** A registration number a vendor may carry: its key, its label and its whole-value shape. */
final readonly class VendorIdentifierOption
{
    public function __construct(
        #[Groups([VendorOptionsResource::READ])]
        public string $key,
        #[Groups([VendorOptionsResource::READ])]
        public string $label,
        /** A regular expression without delimiters, anchored by the preset. */
        #[Groups([VendorOptionsResource::READ])]
        public string $pattern,
    ) {
    }
}
