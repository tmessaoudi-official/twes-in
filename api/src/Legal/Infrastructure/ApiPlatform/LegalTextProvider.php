<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Legal\Application\LegalIdentity;
use App\Legal\Application\ReadLegalText;

/** @implements ProviderInterface<LegalTextResource> */
final readonly class LegalTextProvider implements ProviderInterface
{
    public function __construct(private ReadLegalText $read, private LegalIdentity $identity)
    {
    }

    /** Null, which API Platform answers 404, for a page or a language it does not know and a page never written. */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?LegalTextResource
    {
        $page = LegalTextResource::pageOf($uriVariables['page'] ?? null);
        $language = LegalTextResource::languageOf($uriVariables['language'] ?? null);
        if (null === $page || null === $language) {
            return null;
        }
        $text = $this->read->read($page, $language);

        return null === $text ? null : LegalTextResource::of($text, $this->identity->values());
    }
}
