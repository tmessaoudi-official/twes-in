<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Application;

/** A group still holding customers is kept; move them out first. */
final class CustomerGroupInUse extends \RuntimeException
{
}
