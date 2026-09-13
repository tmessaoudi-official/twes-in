<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain;

interface SettingRepository
{
    /**
     * @param list<SettingAddress> $addresses
     *
     * @return list<Setting> every value stored at any of the addresses, whatever its key
     */
    public function at(array $addresses): array;

    public function find(SettingAddress $address, string $key): ?Setting;

    public function save(Setting $setting): void;

    public function remove(Setting $setting): void;

    /** Forgets every value stored at the address, whatever its key. */
    public function removeAt(SettingAddress $address): void;
}
