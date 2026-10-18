<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

/** No order to a vendor of this company has the id: another company's order is not found either. */
final class PurchaseOrderNotFound extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('No such purchase order.');
    }
}
