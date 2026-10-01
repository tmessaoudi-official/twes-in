<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** Lays a statement of account out as one HTML page, its styles inline, in the page's language. */
interface StatementTemplate
{
    public function html(StatementPage $page): string;
}
