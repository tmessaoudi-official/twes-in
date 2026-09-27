<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

use App\Legal\Domain\LegalLanguage;
use App\Legal\Domain\LegalPage;

final class LegalTextNotWritten extends \DomainException
{
    public function __construct(LegalPage $page, LegalLanguage $language)
    {
        parent::__construct(\sprintf('The page "%s" has no version in "%s" to validate.', $page->value, $language->value));
    }
}
