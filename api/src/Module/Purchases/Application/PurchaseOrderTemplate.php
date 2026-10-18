<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

/** Lays an order to a vendor out as one HTML page, its styles inline, in the page's language. */
interface PurchaseOrderTemplate
{
    public function html(PurchaseOrderPage $page): string;
}
