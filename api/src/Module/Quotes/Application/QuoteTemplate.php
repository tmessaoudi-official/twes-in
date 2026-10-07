<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** Lays a quote out as one HTML page, its styles inline, in the page's language. */
interface QuoteTemplate
{
    public function html(QuotePage $page): string;
}
