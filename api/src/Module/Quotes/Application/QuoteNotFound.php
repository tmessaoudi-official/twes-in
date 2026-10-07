<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

/** A quote that does not exist, or belongs to another company. */
final class QuoteNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such quote.');
    }
}
