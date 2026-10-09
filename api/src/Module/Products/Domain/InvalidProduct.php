<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * A product refused by its own rules or its company's setup; names the field, such as `unitPriceNet`. A refusal whose
 * reason the field does not say on its own also carries a stable reason and its parameters, for a screen or a file's
 * report to translate.
 */
final class InvalidProduct extends \DomainException
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly string $field,
        string $message,
        public readonly ?string $reason = null,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
