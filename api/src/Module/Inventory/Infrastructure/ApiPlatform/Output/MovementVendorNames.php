<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\Output;

use App\Module\Vendors\Application\VendorNameSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Model\Company;

/** Whether the company's movements name a vendor merged away by its own name rather than the vendor kept for it. */
final readonly class MovementVendorNames
{
    public function __construct(private ReadSetting $settings)
    {
    }

    public function asRecorded(Company $company): bool
    {
        return VendorNameSettings::RECORDED === $this->settings->value(new SettingContext($company), VendorNameSettings::MERGED_NAMES);
    }
}
