<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use Symfony\Component\Uid\Uuid;

/**
 * The companies that would be left with no one to run them if this account went: those it is the only owner of.
 * Answered by Tenancy, which holds the memberships.
 */
interface CompaniesOwnedAlone
{
    /** @return list<OwnedAlone> by name */
    public function of(Uuid $userId): array;
}
