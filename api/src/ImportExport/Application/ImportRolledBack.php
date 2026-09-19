<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * Carries a report out of a unit of work that must not be stored: a preview, or a file with a rejected row. Thrown
 * inside Transactions::run so that everything the rows wrote is rolled back, and caught by RunImport alone.
 *
 * @internal
 */
final class ImportRolledBack extends \RuntimeException
{
    public function __construct(public readonly ImportReport $report)
    {
        parent::__construct('The import was rolled back.');
    }
}
