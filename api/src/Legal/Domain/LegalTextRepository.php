<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Domain;

interface LegalTextRepository
{
    /** The page's most recent version in that language, validated or not; null when it was never written in it. */
    public function latest(LegalPage $page, LegalLanguage $language): ?LegalText;

    /** @return list<LegalText> every version of every page, the latest first */
    public function all(): array;

    public function add(LegalText $text): void;

    /** Writes a change to a version already added: its validation. */
    public function save(LegalText $text): void;
}
