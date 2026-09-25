<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** A reorder point a product file named, refused by the inventory's own rule; the message says why. */
final class ReorderPointRefused extends \RuntimeException
{
}
