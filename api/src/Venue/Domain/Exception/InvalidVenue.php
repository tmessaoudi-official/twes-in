<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain\Exception;

/** A drawing refused by its own rules; names the field. */
final class InvalidVenue extends \DomainException
{
    /**
     * @param string|null               $reason a stable code a screen translates, when the field does not say on its own what is wrong
     * @param array<string, string|int> $params that text's parameters
     */
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly ?string $reason = null,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
