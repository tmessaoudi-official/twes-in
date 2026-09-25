<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application\Tej;

/**
 * A month's TEJ declaration that cannot be written: the reason as a stable code and its parameters beside the English
 * message, for a screen to translate, and, when payments lack what the platform asks for, each of them with what it
 * lacks. A file the platform would refuse is never written instead.
 */
final class TejDeclarationRefused extends \DomainException
{
    /**
     * @param array<string, string|int>                                                                                                                          $params
     * @param list<array{expenseId: string, paidOn: string, description: string, reference: string|null, vendorName: string|null, problems: non-empty-list<string>}> $expenses
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $params = [],
        public readonly array $expenses = [],
    ) {
        parent::__construct($message);
    }
}
