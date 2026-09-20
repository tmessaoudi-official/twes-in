<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Domain;

/**
 * A vendor refused by its own rules or its company's preset; names the field, such as `identifiers.matricule_fiscal`.
 *
 * A refusal that HAS a stable code carries it, with its parameters, so a screen or an import report can translate it
 * the way a customer's refusal is translated (docs/SPEC.md § 7, 2026-09-19). The rules that predate those codes fall
 * back to `invalid_value` and their English message, which is what a reader sees until each is given its own code.
 */
final class InvalidVendor extends \DomainException
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly string $reason = 'invalid_value',
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
