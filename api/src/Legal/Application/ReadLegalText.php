<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;
use App\Legal\Domain\LegalText;
use App\Legal\Domain\LegalTextRepository;

/**
 * What a legal page shows: its latest version in the language asked for, else in French, else in English (docs/SPEC.md
 * § 7, 2026-09-27). The latest, validated or not: an unvalidated one says so on the page rather than hiding behind an
 * older text.
 */
final readonly class ReadLegalText
{
    public function __construct(private LegalTextRepository $texts)
    {
    }

    public function read(LegalPage $page, LegalLanguage $language): ?LegalText
    {
        foreach ([$language, ...$language->fallbacks()] as $candidate) {
            $text = $this->texts->latest($page, $candidate);
            if (null !== $text) {
                return $text;
            }
        }

        return null;
    }
}
