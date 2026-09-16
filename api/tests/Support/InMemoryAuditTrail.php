<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Shared\Application\Transactions;

/**
 * Given the use case's transactions, refuses a row written outside one: an audited change commits with its audit row
 * (docs/SPEC.md § 7, 2026-09-16). The functional tests cannot show a missing transaction, since the test bundle keeps
 * one open around every test.
 */
final class InMemoryAuditTrail implements AuditTrail
{
    /** @var list<AuditEntry> */
    public array $entries = [];

    public function __construct(private readonly ?Transactions $transactions = null)
    {
    }

    public function record(AuditEntry $entry): void
    {
        if (null !== $this->transactions && !$this->transactions->active()) {
            throw new \LogicException(\sprintf('%s was audited outside a transaction.', $entry->action));
        }
        $this->entries[] = $entry;
    }
}
