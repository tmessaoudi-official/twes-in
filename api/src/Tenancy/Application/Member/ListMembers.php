<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Member;

use App\Identity\Application\Account\DemoAccounts;
use App\Tenancy\Domain\Model\Invitation;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Repository\InvitationRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Everyone in one company, oldest membership first, then the addresses invited that have not accepted yet, an invitation
 * whose link ran out kept for `app.invitation.expired_shown_for` as expired, so the person who sent it sees it went
 * unused and sends it again rather than wait (workflow hunt, F3). A demo visitor's account shows the address every demo
 * account shows, as it does to the visitor themselves.
 */
final readonly class ListMembers
{
    public function __construct(
        private MembershipRepository $memberships,
        private InvitationRepository $invitations,
        private DemoAccounts $demoAccounts,
        private ClockInterface $clock,
        #[Autowire(param: 'app.invitation.expired_shown_for')]
        private string $expiredShownFor,
    ) {
    }

    /** @return list<MemberView> */
    public function for(Uuid $companyId): array
    {
        $members = array_map(
            fn (Membership $m) => new MemberView(
                $m->getUser()->getId()->toRfc4122(),
                $this->demoAccounts->isDemo($m->getUser()->getId()) ? DemoAccounts::SHOWN_AS : $m->getUser()->getEmail()->value,
                $m->getUser()->getDisplayName(),
                $m->getRole()->getName(),
                $m->getCreatedAt()->format(\DATE_ATOM),
            ),
            $this->memberships->ofCompany($companyId),
        );
        // An invitation names nobody: an existing account is not a member, and not shown as one, until it accepts.
        $now = $this->clock->now();
        $invited = array_map(
            static fn (Invitation $i) => new MemberView(
                null,
                $i->getEmail()->value,
                null,
                $i->getRoleName(),
                null,
                $i->isUsableAt($now) ? MemberView::INVITED : MemberView::EXPIRED,
                $i->getId()->toRfc4122(),
                $i->getExpiresAt()->format(\DATE_ATOM),
            ),
            $this->invitations->unusedOfCompany($companyId, $now->sub(new \DateInterval($this->expiredShownFor))),
        );

        return [...$members, ...$invited];
    }
}
