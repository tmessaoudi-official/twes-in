<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Spreadsheet;

/** The file could not be written: its directory is missing, or there is no room for it. */
final class UnwritableSpreadsheet extends \RuntimeException
{
}
