<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Recurring\Domain\Exception;

/** A recurring invoice refused as written: the field it is about, why in words, and the reason a screen translates. */
final class InvalidRecurringInvoice extends \DomainException
{
    /** @param array<string, string|int> $params what the reason's text names */
    public function __construct(public readonly string $field, string $message, public readonly string $reason = 'invalid_value', public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
