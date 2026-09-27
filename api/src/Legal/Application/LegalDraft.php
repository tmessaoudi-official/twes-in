<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;

/** A starting text the platform ships for one page in one language, for the operator to complete and validate. */
final readonly class LegalDraft
{
    public function __construct(public LegalPage $page, public LegalLanguage $language, public string $body)
    {
    }
}
