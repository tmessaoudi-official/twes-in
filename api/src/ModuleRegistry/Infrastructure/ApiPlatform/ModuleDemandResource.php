<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use App\ModuleRegistry\Application\ModuleDemand;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * How many companies wait for each planned module (« Me prévenir », docs/SPEC.md § 7, 2026-09-26 10:08): the
 * platform operator's reading, across every company, the most asked for first.
 */
#[ApiResource(
    shortName: 'ModuleDemand',
    operations: [
        new GetCollection(
            uriTemplate: '/platform/module-demand',
            provider: ModuleDemandProvider::class,
            security: 'is_granted("'.self::PERMISSION.'")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class ModuleDemandResource
{
    public const string READ = 'module_demand:read';
    public const string PERMISSION = 'platform.modules.read';

    #[ApiProperty(identifier: false, writable: false, required: true)]
    #[Groups([self::READ])]
    public string $key = '';

    /** The translation key of the module's name. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public string $labelKey = '';

    #[ApiProperty(writable: false, required: true, schema: ['type' => 'string', 'enum' => ['v1', 'later']])]
    #[Groups([self::READ])]
    public string $planned = '';

    /** How many companies asked to be told when it arrives. */
    #[ApiProperty(writable: false, required: true)]
    #[Groups([self::READ])]
    public int $companies = 0;

    public static function of(ModuleDemand $demand): self
    {
        $resource = new self();
        $resource->key = $demand->manifest->key;
        $resource->labelKey = $demand->manifest->labelKey;
        $resource->planned = (string) $demand->manifest->planned;
        $resource->companies = $demand->companies;

        return $resource;
    }
}
