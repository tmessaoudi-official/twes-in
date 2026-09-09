<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryMemberships implements MembershipRepository
{
    /** @var list<Membership> */
    private array $memberships = [];

    public function ofUser(Uuid $userId, int $limit): array
    {
        $found = array_values(array_filter($this->memberships, static fn (Membership $m) => $m->getUser()->getId()->equals($userId)));

        return \array_slice($found, 0, $limit);
    }

    public function ofUserInCompany(Uuid $userId, Uuid $companyId): ?Membership
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()->getId()->equals($userId) && $membership->getCompany()->getId()->equals($companyId)) {
                return $membership;
            }
        }

        return null;
    }

    public function save(Membership $membership): void
    {
        $this->memberships[] = $membership;
    }
}
