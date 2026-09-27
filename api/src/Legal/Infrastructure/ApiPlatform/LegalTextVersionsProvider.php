<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Legal\Application\ManageLegalTexts;
use App\Legal\Domain\LegalText;

/** @implements ProviderInterface<PlatformLegalTextResource> */
final readonly class LegalTextVersionsProvider implements ProviderInterface
{
    public function __construct(private ManageLegalTexts $manage)
    {
    }

    /** @return list<PlatformLegalTextResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $path = LegalTextPath::of($uriVariables);

        return array_map(static fn (LegalText $text) => PlatformLegalTextResource::of($text), $this->manage->versions($path->page, $path->language));
    }
}
