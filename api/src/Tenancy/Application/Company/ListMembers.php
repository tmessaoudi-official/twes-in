<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationRepository;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/** Everyone in one company, oldest membership first, then the addresses invited that have not accepted yet. */
final readonly class ListMembers
{
    public function __construct(
        private MembershipRepository $memberships,
        private InvitationRepository $invitations,
        private ClockInterface $clock,
    ) {
    }

    /** @return list<MemberView> */
    public function for(Uuid $companyId): array
    {
        $members = array_map(
            static fn (Membership $m) => new MemberView(
                $m->getUser()->getId()->toRfc4122(),
                $m->getUser()->getEmail()->value,
                $m->getUser()->getDisplayName(),
                $m->getRole()->getName(),
                $m->getCreatedAt()->format(\DATE_ATOM),
            ),
            $this->memberships->ofCompany($companyId),
        );
        // An invitation names nobody: an existing account is not a member, and not shown as one, until it accepts.
        $invited = array_map(
            static fn (Invitation $i) => new MemberView(null, $i->getEmail()->value, null, $i->getRoleName(), null, MemberView::INVITED),
            $this->invitations->openOfCompany($companyId, $this->clock->now()),
        );

        return [...$members, ...$invited];
    }
}
