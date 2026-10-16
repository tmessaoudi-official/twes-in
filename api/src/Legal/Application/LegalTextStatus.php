<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Legal\Domain\Model\LegalText;
use App\Legal\Domain\ValueObject\LegalLanguage;
use App\Legal\Domain\ValueObject\LegalPage;

/** Where one page stands in one language: its latest version, or null when it was never written in it. */
final readonly class LegalTextStatus
{
    public function __construct(public LegalPage $page, public LegalLanguage $language, public ?LegalText $latest)
    {
    }
}
