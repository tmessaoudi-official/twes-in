<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\FirstSteps\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\FirstSteps\Application\FirstStep;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * « Premiers pas » (docs/SPEC.md § 7, 2026-09-25 22:17, row 139): what the signed-in member may still set up in the
 * company, read with company.read, each step shown only to a role that may do it. The home shows it until nothing
 * remains.
 */
#[ApiResource(
    shortName: 'FirstSteps',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/first-steps',
            provider: FirstStepsProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
    ],
)]
final class FirstStepsResource
{
    public const string READ = 'first_steps:read';

    /** How many of the steps shown are not done yet. */
    #[ApiProperty(identifier: false)]
    #[Groups([self::READ])]
    public int $remaining = 0;

    /** @var list<array{key: string, done: bool}> */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'done'],
            'properties' => ['key' => ['type' => 'string'], 'done' => ['type' => 'boolean']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $steps = [];

    /** @param list<FirstStep> $steps */
    public static function of(array $steps): self
    {
        $resource = new self();
        $resource->steps = array_map(static fn (FirstStep $step): array => ['key' => $step->key, 'done' => $step->done], $steps);
        $resource->remaining = \count(array_filter($steps, static fn (FirstStep $step): bool => !$step->done));

        return $resource;
    }
}
