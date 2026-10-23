<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Quotes;

/** No quote of this company has the id: another company's quote is not found either. */
final class DeliveredQuoteNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such quote.');
    }
}
