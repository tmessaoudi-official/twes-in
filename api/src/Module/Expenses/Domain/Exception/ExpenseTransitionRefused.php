<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain\Exception;

/** A change the expense's status does not allow: revising or deleting what is recorded, paying a draft, paying twice. */
final class ExpenseTransitionRefused extends \DomainException
{
    /**
     * @param string                    $reason a stable code the screen says in its own words, the English message being for logs
     * @param array<string, string|int> $params that text's parameters; none, as an expense is refused on its own page
     */
    public function __construct(string $message, public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
