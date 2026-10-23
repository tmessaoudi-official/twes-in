<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application\Invoices;

/** No invoice of this company has the id: another company's invoice is not found either. */
final class DeliveredInvoiceNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such invoice.');
    }
}
