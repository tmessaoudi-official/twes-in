<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/** What the company switcher offers: every company this user belongs to, and their role in each. */
final readonly class ListCompaniesOfUser
{
    /** More companies than one person plausibly works in; the switcher is a menu, not a paginated list. */
    private const int LIMIT = 200;

    public function __construct(private MembershipRepository $memberships)
    {
    }

    /** @return list<CompanySummary> */
    public function for(Uuid $userId): array
    {
        return array_map(
            static fn (Membership $m) => new CompanySummary(
                $m->getCompany()->getId()->toRfc4122(),
                $m->getCompany()->getName(),
                $m->getCompany()->getStatus(),
                $m->getRole()->getName(),
            ),
            $this->memberships->ofUser($userId, self::LIMIT),
        );
    }
}
