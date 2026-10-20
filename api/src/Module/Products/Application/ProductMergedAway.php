<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** The product was merged into another: issued documents still name it, nothing new writes it. */
final class ProductMergedAway extends \RuntimeException
{
}
