<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

/** The account is the only owner of these companies, which would be left with no one to run them. */
final class SoleOwner extends \DomainException
{
    /** @param list<OwnedAlone> $companies */
    public function __construct(public readonly array $companies)
    {
        parent::__construct('The account is the only owner of a company.');
    }
}
