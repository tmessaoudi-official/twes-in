<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

/** The number the series gave is already on another order of the company, as when establishments share a format without `{EST}`. */
final class PurchaseOrderNumberTaken extends \RuntimeException
{
}
