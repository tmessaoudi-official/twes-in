<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Infrastructure\Module;

use App\Module\Scanning\Application\ScannerSwitch;
use App\ModuleRegistry\Application\ModuleStates;
use Symfony\Component\Uid\Uuid;

/** The scanner is on exactly when the company has its module on, as every other module's resources are. */
final readonly class ModuleStatesScannerSwitch implements ScannerSwitch
{
    public function __construct(private ModuleStates $states)
    {
    }

    public function isOn(Uuid $companyId): bool
    {
        return $this->states->isEnabled($companyId, ScanningModule::KEY);
    }
}
