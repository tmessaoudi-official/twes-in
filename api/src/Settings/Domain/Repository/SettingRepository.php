<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain\Repository;

use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tenancy\Domain\Model\Company;

interface SettingRepository
{
    /**
     * @param list<SettingAddress> $addresses
     *
     * @return list<Setting> every value stored at any of the addresses, whatever its key
     */
    public function at(array $addresses): array;

    /**
     * @param list<SettingLevel> $levels
     *
     * @return list<Setting> every value of the key the company's own subjects hold at those levels
     */
    public function heldBy(Company $company, string $key, array $levels): array;

    public function find(SettingAddress $address, string $key): ?Setting;

    /** The same, its row held until the transaction this runs in ends; outside a transaction it refuses. */
    public function lockedFind(SettingAddress $address, string $key): ?Setting;

    /** A first value of a key meeting another written meanwhile at the same level is refused as a save made since. */
    public function save(Setting $setting): void;

    public function remove(Setting $setting): void;

    /** Forgets every value stored at the address, whatever its key. */
    public function removeAt(SettingAddress $address): void;
}
