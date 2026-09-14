<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/**
 * The number a series gave is already on another document of the same type of the company, which happens when two
 * establishments number with the same format (docs/SPEC.md § 7, 2026-09-13: unique numbers within a company).
 */
final class InvoiceNumberTaken extends \DomainException
{
}
