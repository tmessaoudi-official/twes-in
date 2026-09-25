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

/**
 * The company a sign-in opens (docs/SPEC.md § 7, 2026-09-25 09:03): the one the person pinned, else the one they last
 * worked in, else the first by name. A person who belongs to a company never lands on « aucune entreprise »; one who
 * belongs to none works in nothing.
 */
final readonly class ChooseWorkingCompany
{
    public function __construct(private MembershipRepository $memberships, private CurrentCompany $currentCompany)
    {
    }

    /**
     * Reads, never writes: only the switcher records a use. A sign-in writing one would tie, to the second the column
     * keeps, with a switch made right after it, and the next sign-in would read the tie by name.
     */
    public function for(Uuid $userId): void
    {
        $this->currentCompany->set($this->memberships->toOpenAtSignIn($userId)?->getCompany()->getId());
    }
}
