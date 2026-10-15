<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Numbering;

/** A format that could print a number another series of the same document type prints, refused when it is written. */
final class NumberingSeriesOverlap extends \RuntimeException
{
}
