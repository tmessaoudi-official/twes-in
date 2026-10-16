<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Identity\Application\Mfa\SecondFactors;
use App\Identity\Domain\Model\Passkey;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Tenancy\Application\Permission\KnownPermissions;
use App\Tenancy\Application\Support\RecordSupportVisit;
use App\Tenancy\Application\Support\SupportAccess;
use App\Tenancy\Application\Support\SupportVisits;
use App\Tenancy\Domain\MembershipRepository;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** « Accès support » for the unit tests of what asks it: no grant unless a test saves one. */
final class SupportDoubles
{
    public static function access(MembershipRepository $memberships, InMemoryUsers $users = new InMemoryUsers(), InMemorySupportGrants $grants = new InMemorySupportGrants(), ?MockClock $clock = null): SupportAccess
    {
        return new SupportAccess($users, $memberships, $grants, new SecondFactors(self::noPasskeys()), $clock ?? new MockClock());
    }

    public static function visits(): RecordSupportVisit
    {
        return new RecordSupportVisit(new InMemoryAuditTrail(), new class implements SupportVisits {
            public function readSince(Uuid $companyId, Uuid $userId, string $page, \DateTimeImmutable $since): bool
            {
                return false;
            }
        }, new FakeTransactions(), new MockClock());
    }

    public static function permissions(): KnownPermissions
    {
        return new class implements KnownPermissions {
            public function groups(): array
            {
                return [];
            }

            public function knows(string $permission): bool
            {
                return false;
            }
        };
    }

    private static function noPasskeys(): PasskeyRepository
    {
        return new class implements PasskeyRepository {
            public function ofUser(User $user): array
            {
                return [];
            }

            public function ofUserAndId(User $user, Uuid $id): ?Passkey
            {
                return null;
            }

            public function ofUserAndCredentialId(User $user, string $credentialId): ?Passkey
            {
                return null;
            }

            public function existsWithCredentialId(string $credentialId): bool
            {
                return false;
            }

            public function countFor(User $user): int
            {
                return 0;
            }

            public function save(Passkey $passkey): void
            {
            }

            public function remove(Passkey $passkey): void
            {
            }
        };
    }
}
