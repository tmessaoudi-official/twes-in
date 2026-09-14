<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/** A change an invoice's status no longer allows: only a draft is revised or issued. */
final class InvoiceNotDraft extends \DomainException
{
}
