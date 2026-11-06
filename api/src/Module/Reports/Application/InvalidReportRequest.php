<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Application;

/** What was asked of a report cannot be read: a period backwards or too long, an establishment a report has no say over. */
final class InvalidReportRequest extends \DomainException
{
    /**
     * @param string                    $field  what the address names that is refused: `to`, `compare`, `establishment`
     * @param string                    $reason a stable code a screen translates
     * @param array<string, int|string> $params that text's parameters
     */
    public function __construct(string $message, public readonly string $field, public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
