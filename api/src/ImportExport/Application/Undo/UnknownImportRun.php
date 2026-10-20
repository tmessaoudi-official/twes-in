<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

/** No import of this company has that id: a 404, the same as another company's. */
final class UnknownImportRun extends \RuntimeException
{
}
