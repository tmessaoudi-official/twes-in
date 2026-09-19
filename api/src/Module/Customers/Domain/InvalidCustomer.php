<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

/**
 * A customer refused by its own rules or its company's preset; names the field, such as `identifiers.siren`, and gives
 * the reason as a stable code and its parameters beside the English message, for a screen to translate (docs/SPEC.md
 * § 7, 2026-09-19).
 */
final class InvalidCustomer extends \DomainException
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly string $reason,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
