<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

/** An operator's allowance of earlier days refused: no such company, or more hours than the installation allows. */
final class EarlierDaysRefused extends \RuntimeException
{
}
