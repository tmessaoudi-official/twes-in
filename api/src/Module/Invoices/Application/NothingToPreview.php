<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** A design is previewed on the company's own latest invoice, and it has none yet. */
final class NothingToPreview extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('The company has no invoice to show a design on yet.');
    }
}
