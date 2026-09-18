<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Spreadsheet;

/**
 * The file is not a spreadsheet of the format it claims: a .xlsx that is not a workbook, a file that is not there.
 *
 * This is the whole FILE being refused, never one of its rows. A row that cannot be made sense of is an outcome the
 * import preview reports with its reason and its line number, not an exception.
 */
final class UnreadableSpreadsheet extends \RuntimeException
{
}
