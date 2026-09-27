<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Infrastructure\ApiPlatform;

use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** The page and language a platform path names, or a 404 for one it does not know. */
final readonly class LegalTextPath
{
    private function __construct(public LegalPage $page, public LegalLanguage $language)
    {
    }

    /** @param array<string, mixed> $uriVariables */
    public static function of(array $uriVariables): self
    {
        $page = LegalTextResource::pageOf($uriVariables['page'] ?? null);
        $language = LegalTextResource::languageOf($uriVariables['language'] ?? null);
        if (null === $page || null === $language) {
            throw new NotFoundHttpException('No such legal page or language.');
        }

        return new self($page, $language);
    }
}
