<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Purchases;

use App\Module\Purchases\Application\Receivers\ReceivingMember;
use App\Module\Purchases\Application\Receivers\ReceivingMembers;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the purchases module's `ReceivingMembers` port out of the memberships: each member by the name they go by,
 * their account as the link, never their address. A member is named by their account, so someone who leaves and joins
 * again is the same person on the receipts they received.
 */
final readonly class MembersForReceipts implements ReceivingMembers
{
    public function __construct(private MembershipRepository $memberships)
    {
    }

    public function membersOf(Company $company, ?Uuid $first): array
    {
        $members = array_map(
            static fn (Membership $membership): ReceivingMember => new ReceivingMember($membership->getUser()->getId(), $membership->getUser()->getDisplayName()),
            $this->memberships->ofCompany($company->getId()),
        );
        $isFirst = static fn (ReceivingMember $member): bool => null !== $first && $member->userId->equals($first);

        return [...array_values(array_filter($members, $isFirst)), ...array_values(array_filter($members, static fn (ReceivingMember $member): bool => !$isFirst($member)))];
    }

    public function nameOf(Company $company, Uuid $userId): ?string
    {
        return $this->memberships->ofUserInCompany($userId, $company->getId())?->getUser()->getDisplayName();
    }
}
