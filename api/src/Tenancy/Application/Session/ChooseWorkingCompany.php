<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Shared\Application\CurrentCompany;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/** One membership: work in that company from the first request. Several: the switcher (G1b) decides. None: nothing. */
final readonly class ChooseWorkingCompany
{
    public function __construct(private MembershipRepository $memberships, private CurrentCompany $currentCompany)
    {
    }

    public function for(Uuid $userId): void
    {
        $memberships = $this->memberships->ofUser($userId, 2);
        $this->currentCompany->set(1 === \count($memberships) ? $memberships[0]->getCompany()->getId() : null);
    }
}
