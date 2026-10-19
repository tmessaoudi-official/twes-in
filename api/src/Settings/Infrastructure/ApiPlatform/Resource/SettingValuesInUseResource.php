<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Settings\Infrastructure\ApiPlatform\State\Provider\SettingValuesInUseProvider;

/**
 * What a setting of the parties chain holds across a company: the value in force for the company, and the values its
 * customer groups and customers hold of their own, told only to whoever may read customers (null otherwise). The
 * names of a unit or a tax are offered in the document languages these say the company prints in.
 */
#[ApiResource(
    shortName: 'SettingValuesInUse',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/settings/{key}/in-use',
            requirements: ['key' => SettingResource::KEY],
            provider: SettingValuesInUseProvider::class,
            security: 'is_granted("ROLE_USER")',
            // skip_null_values off: null parties means « not told », which a missing key would not say.
            normalizationContext: ['skip_null_values' => false],
        ),
    ],
)]
final class SettingValuesInUseResource
{
    #[ApiProperty(identifier: true)]
    public string $key = '';

    #[ApiProperty(schema: SettingResource::ANY_VALUE)]
    public mixed $company = null;

    /** @var list<mixed>|null */
    #[ApiProperty(schema: ['anyOf' => [['type' => 'array', 'items' => SettingResource::ANY_VALUE], ['type' => 'null']]])]
    public ?array $parties = null;
}
