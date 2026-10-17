<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Demo\Application\DemoSettings;
use App\Demo\Domain\Model\DemoSandbox;
use App\Demo\Infrastructure\ApiPlatform\Output\DemoCountry;
use App\Demo\Infrastructure\ApiPlatform\Resource\DemoAvailability;
use App\Demo\Infrastructure\Dataset\DemoCatalogue;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;

/**
 * Whether the demo is open, and in which countries, with the company each holds: what the sign-in page needs before
 * anybody has a session. A sandbox's country with no company in the catalogue fails here, as it would fail its build.
 *
 * @implements ProviderInterface<DemoAvailability>
 */
final readonly class DemoAvailabilityProvider implements ProviderInterface
{
    public function __construct(private ReadSetting $settings)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DemoAvailability
    {
        if (true !== $this->settings->value(new SettingContext(), DemoSettings::ENABLED)) {
            return new DemoAvailability(false, []);
        }

        return new DemoAvailability(true, array_map(static fn (string $country): DemoCountry => new DemoCountry($country, DemoCatalogue::of($country)->name), DemoSandbox::COUNTRIES));
    }
}
