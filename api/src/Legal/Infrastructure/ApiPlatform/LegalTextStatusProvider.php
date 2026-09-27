<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Legal\Application\LegalTextStatus;
use App\Legal\Application\ManageLegalTexts;

/** @implements ProviderInterface<LegalTextStatusResource> */
final readonly class LegalTextStatusProvider implements ProviderInterface
{
    public function __construct(private ManageLegalTexts $manage)
    {
    }

    /** @return list<LegalTextStatusResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        return array_map(static fn (LegalTextStatus $status) => LegalTextStatusResource::of($status), $this->manage->overview());
    }
}
