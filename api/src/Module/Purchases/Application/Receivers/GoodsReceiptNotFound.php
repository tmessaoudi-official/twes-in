<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application\Receivers;

/** No receipt of this id came against the order named: another order's, or none. */
final class GoodsReceiptNotFound extends \RuntimeException
{
}
