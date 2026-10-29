<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Preset;

use App\Settings\Application\DeclaresSettings;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Settings\Domain\ValueObject\SettingType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The platform's own country, which an operator picks under « Gérer la plateforme »: the phones of the platform's
 * support and of its legal pages start their picker on it, as a company's forms start on the company's. It is one of the
 * countries a fiscal preset is written for, and the installation's own (`app.platform.country`) until an operator picks
 * another; an installation naming a country no preset is written for is refused when the settings are read.
 */
final readonly class PlatformCountrySettings implements DeclaresSettings
{
    public const string MODULE = 'core';
    public const string COUNTRY = 'platform.country';

    public function __construct(
        private FiscalPresets $presets,
        #[Autowire(param: 'app.platform.country')]
        private string $installationCountry,
    ) {
    }

    public function settings(): iterable
    {
        yield new SettingDefinition(self::COUNTRY, SettingType::Enum, $this->installationCountry, SettingChain::Platform, [SettingLevel::Platform], 'settings.platform.country', self::MODULE, choices: $this->presets->keys());
    }
}
