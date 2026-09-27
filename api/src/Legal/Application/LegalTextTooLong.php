<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Legal\Application;

final class LegalTextTooLong extends \DomainException
{
    public function __construct(public readonly int $maxLength)
    {
        parent::__construct(\sprintf('A legal text holds at most %d characters.', $maxLength));
    }
}
