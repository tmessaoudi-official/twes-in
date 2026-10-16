<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/**
 * No net price comes, with the product's line taxes, to the price written taxes included: the currency's last digit
 * falls between two that do, such as 9.99 at 20 % in euros (8.32 makes 9.98, 8.33 makes 10.00).
 */
final class GrossUnreachable extends \DomainException
{
    /**
     * @param string $below the nearest price taxes included under it that a net price comes to
     * @param string $above the nearest over it
     */
    public function __construct(
        public readonly string $gross,
        public readonly string $below,
        public readonly string $above,
    ) {
        parent::__construct(\sprintf('No price without taxes comes to %s with these taxes: the nearest are %s and %s.', $gross, $below, $above));
    }
}
