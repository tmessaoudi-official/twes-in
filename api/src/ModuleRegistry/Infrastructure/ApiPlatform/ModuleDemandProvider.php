<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ModuleRegistry\Application\ModuleDemand;
use App\ModuleRegistry\Application\ModuleInterests;

/** @implements ProviderInterface<ModuleDemandResource> */
final readonly class ModuleDemandProvider implements ProviderInterface
{
    public function __construct(private ModuleInterests $interests)
    {
    }

    /** @return list<ModuleDemandResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(static fn (ModuleDemand $demand) => ModuleDemandResource::of($demand), $this->interests->demand());
    }
}
