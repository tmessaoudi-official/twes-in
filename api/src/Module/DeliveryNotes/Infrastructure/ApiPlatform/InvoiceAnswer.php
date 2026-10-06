<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use App\Module\Invoices\Domain\Invoice;
use App\Tenancy\Domain\Company;

/**
 * An invoice drafted from delivery notes, as the API answers any invoice: with its figures and what each line may take of
 * its note. A port this module owns, answered by the invoices', which own that answer, so neither calls into the other
 * (docs/SPEC.md § 7, audit 2026-10-06 C-4).
 */
interface InvoiceAnswer
{
    public function of(Company $company, Invoice $invoice): object;
}
