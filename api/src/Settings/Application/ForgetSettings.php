<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingRepository;

/**
 * Forgets every value stored for one subject when the subject itself goes: a deleted customer group leaves no
 * default behind for a group that no longer exists. The subject's deletion is what the audit trail records.
 */
final readonly class ForgetSettings
{
    public function __construct(private SettingRepository $settings)
    {
    }

    public function at(SettingAddress $address): void
    {
        $this->settings->removeAt($address);
    }
}
