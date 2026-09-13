<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingRepository;

final class InMemorySettings implements SettingRepository
{
    /** @var list<Setting> */
    public array $settings = [];

    public function at(array $addresses): array
    {
        return array_values(array_filter(
            $this->settings,
            static fn (Setting $setting) => array_any($addresses, static fn (SettingAddress $address) => $setting->isAt($address)),
        ));
    }

    public function find(SettingAddress $address, string $key): ?Setting
    {
        foreach ($this->settings as $setting) {
            if ($setting->isAt($address) && $setting->getKey() === $key) {
                return $setting;
            }
        }

        return null;
    }

    public function save(Setting $setting): void
    {
        if (!\in_array($setting, $this->settings, true)) {
            $this->settings[] = $setting;
        }
    }

    public function remove(Setting $setting): void
    {
        $this->settings = array_values(array_filter($this->settings, static fn (Setting $each) => $each !== $setting));
    }

    public function removeAt(SettingAddress $address): void
    {
        $this->settings = array_values(array_filter($this->settings, static fn (Setting $each) => !$each->isAt($address)));
    }
}
