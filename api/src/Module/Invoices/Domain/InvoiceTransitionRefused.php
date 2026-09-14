<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/** A move an invoice's status does not allow: only a draft is cancelled, only an issued invoice is paid or credited. */
final class InvoiceTransitionRefused extends \DomainException
{
}
