<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Domain\Exception;

/**
 * Terms a subscription cannot hold: the field refused, the reason the screens translate, and what that text names, so
 * the operator reads each refusal under the term it is about.
 */
final class InvalidSubscription extends \DomainException
{
    /** Neither a trial's end nor a paid day: the terms cover nothing. */
    public const string TRIAL_OR_PAID = 'trial_or_paid_required';

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
