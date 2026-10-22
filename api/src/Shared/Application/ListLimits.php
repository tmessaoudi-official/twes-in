<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * The most entries a list handed in may hold, the operator's to set (`app.list_limits`): a request's work grows with
 * its lists, and one document of ten thousand lines held a worker for fifteen seconds.
 */
interface ListLimits
{
    /** A document's lines: an invoice, a quote, a delivery note, an order to a vendor, an expense, a receipt. */
    public const string DOCUMENT_LINES = 'document.lines';

    /** At least 1. */
    public function most(string $list): int;
}
