<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/** Asked for something no module declares as importable: a 404, not a failure. */
final class UnknownImportSubject extends \RuntimeException
{
}
