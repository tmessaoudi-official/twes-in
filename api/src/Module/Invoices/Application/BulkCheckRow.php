<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

use App\Module\Invoices\Domain\Invoice;
use Symfony\Component\Uid\Uuid;

/**
 * One selected id and what a bulk action would do with it: the invoice goes in, or it is skipped for a reason a screen
 * translates. An id that is no invoice of the company has no invoice to show.
 */
final readonly class BulkCheckRow
{
    public const string NOT_FOUND = 'not_found';

    public function __construct(public Uuid $id, public ?Invoice $invoice, public ?string $skipped = null)
    {
    }

    public function included(): bool
    {
        return null === $this->skipped;
    }
}
