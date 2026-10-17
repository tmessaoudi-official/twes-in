<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Demo\Infrastructure\ApiPlatform\Output\DemoCountry;
use App\Demo\Infrastructure\ApiPlatform\State\Provider\DemoAvailabilityProvider;

/**
 * Whether the sign-in page offers « Essayer la démo », and the countries a visitor picks from, each with the company its
 * sandbox holds. Read with no session; entering is `POST /api/auth/demo` (DemoEntryController).
 */
#[ApiResource(
    shortName: 'DemoAvailability',
    operations: [new Get(uriTemplate: '/auth/demo', name: 'api_auth_demo_availability', provider: DemoAvailabilityProvider::class)],
)]
final readonly class DemoAvailability
{
    /** @param list<DemoCountry> $countries in the order the visitor is offered them, none while the demo is off */
    public function __construct(
        #[ApiProperty(required: true)] public bool $enabled,
        #[ApiProperty(required: true)] public array $countries,
    ) {
    }
}
