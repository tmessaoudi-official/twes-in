<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Session;

use App\Shared\Application\CurrentCompany;
use App\Tenancy\Application\Company\NotAMember;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * The switcher. The membership is checked here and not by the caller: the session company decides what every
 * later request may read, so an unchecked identifier would be a cross-company hole.
 */
final readonly class SwitchWorkingCompany
{
    public function __construct(
        private MembershipRepository $memberships,
        private CurrentCompany $currentCompany,
    ) {
    }

    /** @throws NotAMember */
    public function to(Uuid $userId, Uuid $companyId): void
    {
        if (null === $this->memberships->ofUserInCompany($userId, $companyId)) {
            throw new NotAMember(\sprintf('%s is not a member of that company.', $userId->toRfc4122()));
        }

        $this->currentCompany->set($companyId);
    }
}
