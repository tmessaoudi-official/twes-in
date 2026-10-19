<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\Repository\SettingRepository;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tenancy\Domain\Model\Company;

/**
 * The values a setting of the parties chain takes across one company: the one in force for the company itself, and
 * those its customer groups and customers hold of their own. A screen offering a field per language reads, for
 * example, which document languages its company prints in. A document's own value is a snapshot of one sale and
 * says nothing of what the company prints in, so it is left out.
 */
final readonly class SettingValuesInUse
{
    private const array PARTY_LEVELS = [SettingLevel::CustomerGroup, SettingLevel::Customer];

    public function __construct(
        private SettingCatalog $catalog,
        private ReadSetting $read,
        private SettingRepository $settings,
    ) {
    }

    /** @throws UnknownSetting when no parties setting has this key */
    public function ofCompany(Company $company, string $key): mixed
    {
        $this->partiesSetting($key);

        return $this->read->value(new SettingContext($company), $key);
    }

    /**
     * @return list<mixed> each value once, in a stable order
     *
     * @throws UnknownSetting when no parties setting has this key
     */
    public function ofParties(Company $company, string $key): array
    {
        $this->partiesSetting($key);
        $values = [];
        foreach ($this->settings->heldBy($company, $key, self::PARTY_LEVELS) as $setting) {
            $values[self::identity($setting)] = $setting->getValue();
        }
        ksort($values, \SORT_STRING);

        return array_values($values);
    }

    private function partiesSetting(string $key): void
    {
        if (SettingChain::Parties !== $this->catalog->definitionOf($key)?->chain) {
            throw new UnknownSetting(\sprintf('No parties setting is called %s.', $key));
        }
    }

    /** Two values are one when they are written alike, whatever their type. */
    private static function identity(Setting $setting): string
    {
        return json_encode($setting->getValue(), \JSON_THROW_ON_ERROR);
    }
}
