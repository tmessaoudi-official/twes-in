<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** None of the invoices selected goes into a PDF: all are gone, or drafts and cancelled ones not asked for. */
final class NothingToPrint extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('None of the invoices selected is printed.');
    }
}
