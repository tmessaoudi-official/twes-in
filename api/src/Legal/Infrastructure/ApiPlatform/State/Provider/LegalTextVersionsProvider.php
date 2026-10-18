<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Legal\Application\LegalLanguages;
use App\Legal\Application\ManageLegalTexts;
use App\Legal\Domain\Model\LegalText;
use App\Legal\Infrastructure\ApiPlatform\Payload\LegalTextPath;
use App\Legal\Infrastructure\ApiPlatform\Resource\PlatformLegalTextResource;

/** @implements ProviderInterface<PlatformLegalTextResource> */
final readonly class LegalTextVersionsProvider implements ProviderInterface
{
    public function __construct(private ManageLegalTexts $manage, private LegalLanguages $languages)
    {
    }

    /** @return list<PlatformLegalTextResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $path = LegalTextPath::of($uriVariables, $this->languages);

        return array_map(static fn (LegalText $text) => PlatformLegalTextResource::of($text), $this->manage->versions($path->page, $path->language));
    }
}
