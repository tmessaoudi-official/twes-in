<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain\Exception;

/**
 * A payment that cannot be declared, or a decision made twice: the field refused (empty for the request as a whole), the
 * reason the screens translate, and what that text names.
 */
final class InvalidPayment extends \DomainException
{
    public const string ALREADY_DECIDED = 'payment_already_decided';

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
