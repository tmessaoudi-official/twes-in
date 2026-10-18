<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\Module;

use App\Module\Reports\Application\ReportCatalogue;
use App\ModuleRegistry\Application\DeclaresModule;
use App\ModuleRegistry\Application\ModuleManifest;

/**
 * Reports: the figures each module declares, read over a period against another (docs/SPEC.md § 7, 2026-09-20 03:40). It
 * brings no permission of its own: a report is read with its subject's permission, so nobody reads in a report what the
 * subject's list would not show them.
 */
final readonly class ReportsModule implements DeclaresModule
{
    public const string KEY = ReportCatalogue::MODULE;

    public function manifest(): ModuleManifest
    {
        return new ModuleManifest(self::KEY, 'modules.reports');
    }
}
