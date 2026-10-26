<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Domain\ValueObject;

/**
 * Where an action is recorded: in a company, which its journal then shows, outside any (an account's, the platform's),
 * or either (a setting the platform or a company holds). What a journal shows is read from here, never from a list kept
 * beside it.
 */
enum AuditScope: string
{
    case Company = 'company';
    case Outside = 'outside';
    case Either = 'either';

    public function allows(bool $inACompany): bool
    {
        return match ($this) {
            self::Company => $inACompany,
            self::Outside => !$inACompany,
            self::Either => true,
        };
    }

    /** Whether a company's journal can show it, and so must say it in words. */
    public function shownInAJournal(): bool
    {
        return self::Outside !== $this;
    }
}
