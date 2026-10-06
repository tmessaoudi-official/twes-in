<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Scanning\Application;

use Symfony\Component\Uid\Uuid;

/**
 * Whether a company has the scanner on (docs/SPEC.md § 7, 2026-10-06 21:02). The tab's endpoints name their company
 * and the module guard answers them; the phone's name only a pairing, so its use case asks here.
 */
interface ScannerSwitch
{
    public function isOn(Uuid $companyId): bool;
}
