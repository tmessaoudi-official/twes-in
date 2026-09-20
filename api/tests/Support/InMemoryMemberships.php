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

    public function anyCompanyRequiresMfa(Uuid $userId): bool
    {
        foreach ($this->memberships as $membership) {
            if ($membership->getUser()->getId()->equals($userId) && $membership->getCompany()->isMfaRequired()) {
                return true;
            }
        }

        return false;
    }

    public function ofUser(Uuid $userId, int $limit): array
    {
        $found = array_values(array_filter($this->memberships, static fn (Membership $m) => $m->getUser()->getId()->equals($userId)));

        return \array_slice($found, 0, $limit);
    }

    public function ofCompany(Uuid $companyId): array
    {
        return array_values(array_filter($this->memberships, static fn (Membership $m) => $m->getCompany()->getId()->equals($companyId)));
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

    public function countByRole(Uuid $companyId): array
    {
        $counts = [];
        foreach ($this->ofCompany($companyId) as $membership) {
            $key = $membership->getRole()->getId()->toRfc4122();
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        return $counts;
    }

    public function holdersOfRole(Uuid $companyId, Uuid $roleId, int $limit): array
    {
        $held = array_filter($this->ofCompany($companyId), static fn (Membership $m): bool => $m->getRole()->getId()->equals($roleId));
        $emails = array_map(static fn (Membership $m): string => (string) $m->getUser()->getEmail(), array_values($held));

        return \array_slice($emails, 0, $limit);
    }

    public function save(Membership $membership): void
    {
        if (!\in_array($membership, $this->memberships, true)) {
            $this->memberships[] = $membership;
        }
    }

    public function remove(Membership $membership): void
    {
        $this->memberships = array_values(array_filter($this->memberships, static fn (Membership $m) => $m !== $membership));
    }
}
