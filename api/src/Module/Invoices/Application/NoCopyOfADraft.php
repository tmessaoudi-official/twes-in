<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application;

/** A draft or a cancelled draft has no number and no original, so it has nothing to be a copy of. */
final class NoCopyOfADraft extends \DomainException
{
    public function __construct()
    {
        parent::__construct('Only an issued invoice has a copy.');
    }
}
