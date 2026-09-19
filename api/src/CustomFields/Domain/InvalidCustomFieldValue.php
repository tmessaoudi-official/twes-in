<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Domain;

/**
 * A record's custom field value refused, naming it as `customFields.<key>`, with the reason as a stable code and its
 * parameters beside the English message, for a screen to translate (docs/SPEC.md § 7, 2026-09-19).
 */
final class InvalidCustomFieldValue extends \DomainException
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
