<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

/**
 * A profile its fiscal preset refuses; names the field, such as `identifiers.matricule_fiscal`, so the caller can point at it,
 * and the reason a screen says it with, the import's own codes.
 */
final class InvalidCompanyProfile extends \DomainException
{
    /** @param array<string, string|int> $params what the reason's text needs */
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly string $reason = 'invalid_value',
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
