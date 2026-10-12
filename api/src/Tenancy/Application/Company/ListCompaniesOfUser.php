<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\SupportGrant;
use Symfony\Component\Uid\Uuid;

/**
 * What the company switcher offers: every company this user belongs to, and their role in each; for an operator, after
 * them, every company whose owner lets support read it, marked as such.
 */
final readonly class ListCompaniesOfUser
{
    /** More companies than one person plausibly works in; the switcher is a menu, not a paginated list. */
    private const int LIMIT = 200;

    public function __construct(private MembershipRepository $memberships, private CompanyLogo $logos, private SupportAccess $support)
    {
    }

    /** @return list<CompanySummary> */
    public function for(Uuid $userId): array
    {
        $memberships = $this->memberships->ofUser($userId, self::LIMIT);
        $member = array_map(static fn (Membership $m): string => $m->getCompany()->getId()->toRfc4122(), $memberships);
        $grants = array_values(array_filter(
            $this->support->grantsFor($userId, self::LIMIT),
            static fn (SupportGrant $grant): bool => !\in_array($grant->getCompany()->getId()->toRfc4122(), $member, true),
        ));
        $logos = $this->logos->versionsOf([
            ...array_map(static fn (Membership $m): Uuid => $m->getCompany()->getId(), $memberships),
            ...array_map(static fn (SupportGrant $grant): Uuid => $grant->getCompany()->getId(), $grants),
        ]);

        return [...array_map(
            static fn (Membership $m) => new CompanySummary(
                $m->getCompany()->getId()->toRfc4122(),
                $m->getCompany()->getName(),
                $m->getCompany()->getStatus(),
                $m->getRole()->getName(),
                $m->isOpenedAtSignIn(),
                $logos[$m->getCompany()->getId()->toRfc4122()] ?? null,
            ),
            $memberships,
        ), ...array_map(
            static fn (SupportGrant $grant) => new CompanySummary(
                $grant->getCompany()->getId()->toRfc4122(),
                $grant->getCompany()->getName(),
                $grant->getCompany()->getStatus(),
                SupportAccess::ROLE,
                false,
                $logos[$grant->getCompany()->getId()->toRfc4122()] ?? null,
                $grant->getEndsAt(),
            ),
            $grants,
        )];
    }
}
