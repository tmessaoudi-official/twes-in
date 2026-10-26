<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Purchases\Application\Receivers\ReceivingMember;
use App\Module\Purchases\Application\Receivers\ReceivingMembers;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** The members of each company a receipt may name, held in memory: the oldest first, as the tenancy answers them. */
final class InMemoryReceivingMembers implements ReceivingMembers
{
    /** @var array<string, list<ReceivingMember>> by company id */
    private array $members = [];

    public function join(Company $company, string $name): Uuid
    {
        $id = Uuid::v7();
        $this->members[$company->getId()->toRfc4122()][] = new ReceivingMember($id, $name);

        return $id;
    }

    public function rename(Company $company, Uuid $userId, string $name): void
    {
        $this->members[$company->getId()->toRfc4122()] = array_map(
            static fn (ReceivingMember $member): ReceivingMember => $member->userId->equals($userId) ? new ReceivingMember($userId, $name) : $member,
            $this->members[$company->getId()->toRfc4122()] ?? [],
        );
    }

    public function membersOf(Company $company, ?Uuid $first): array
    {
        $members = $this->members[$company->getId()->toRfc4122()] ?? [];
        $isFirst = static fn (ReceivingMember $member): bool => null !== $first && $member->userId->equals($first);

        return [...array_values(array_filter($members, $isFirst)), ...array_values(array_filter($members, static fn (ReceivingMember $member): bool => !$isFirst($member)))];
    }

    public function nameOf(Company $company, Uuid $userId): ?string
    {
        foreach ($this->members[$company->getId()->toRfc4122()] ?? [] as $member) {
            if ($member->userId->equals($userId)) {
                return $member->name;
            }
        }

        return null;
    }
}
