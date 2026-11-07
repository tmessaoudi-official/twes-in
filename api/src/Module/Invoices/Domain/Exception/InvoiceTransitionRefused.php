<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain\Exception;

/** A move an invoice's status does not allow: only a draft is cancelled, only an issued invoice is paid or credited. */
final class InvoiceTransitionRefused extends \DomainException
{
    /**
     * @param string                    $reason a stable code the screen says in its own words, the English message being for logs
     * @param array<string, string|int> $params that text's parameters, such as the invoice's `reference`
     */
    public function __construct(string $message, public readonly string $reason, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
