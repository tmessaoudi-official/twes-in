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

/** Everyone in one company, oldest membership first. */
final readonly class ListMembers
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    /** @return list<MemberView> */
    public function for(Uuid $companyId): array
    {
        return array_map(
            static fn (Membership $m) => new MemberView(
                $m->getUser()->getId()->toRfc4122(),
                $m->getUser()->getEmail()->value,
                $m->getUser()->getDisplayName(),
                $m->getRole()->getName(),
                $m->getCreatedAt()->format(\DATE_ATOM),
            ),
            $this->memberships->ofCompany($companyId),
        );
    }
}
