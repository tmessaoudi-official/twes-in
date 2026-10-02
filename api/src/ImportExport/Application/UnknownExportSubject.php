<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/** Nothing of that name can be exported, or its module is switched off for the company. */
final class UnknownExportSubject extends \RuntimeException
{
}
