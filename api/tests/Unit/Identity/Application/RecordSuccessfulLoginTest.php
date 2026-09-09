<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Login\LoginAudit;
use App\Identity\Application\Login\RecordLogout;
use App\Identity\Application\Login\RecordSuccessfulLogin;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCurrentCompany;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class RecordSuccessfulLoginTest extends TestCase
{
    public function testItResetsTheCounterStampsTheLoginAndAuditsItInTheSessionCompany(): void
    {
        $users = new InMemoryUsers();
        $audit = new InMemoryAuditTrail();
        $clock = new MockClock('2026-09-09 12:00:00');
        $companyId = Uuid::v7();
        $user = new User(Email::fromString('u@example.test'), 'U');
        $user->recordFailedLogin($clock->now(), 5, new \DateInterval('PT15M'));
        $users->save($user);

        (new RecordSuccessfulLogin($users, $audit, new InMemoryCurrentCompany($companyId), $clock))->handle($user->getId());

        self::assertSame(0, $user->getFailedLoginCount());
        self::assertEquals($clock->now(), $user->getLastLoginAt());
        $entry = $audit->entries[0];
        self::assertSame(LoginAudit::LOGIN, $entry->action);
        self::assertTrue($user->getId()->equals($entry->actorUserId ?? throw new \LogicException()));
        self::assertTrue($companyId->equals($entry->companyId ?? throw new \LogicException()));
    }

    public function testAnUnknownIdIsARefusedInvariantNotASilentNoOp(): void
    {
        $useCase = new RecordSuccessfulLogin(new InMemoryUsers(), new InMemoryAuditTrail(), new InMemoryCurrentCompany(), new MockClock());

        $this->expectException(\DomainException::class);
        $useCase->handle(Uuid::v7());
    }

    public function testALogoutIsAuditedByTheUserWhoLeaves(): void
    {
        $audit = new InMemoryAuditTrail();
        $userId = Uuid::v7();

        (new RecordLogout($audit, new InMemoryCurrentCompany()))->handle($userId);

        $entry = $audit->entries[0];
        self::assertSame(LoginAudit::LOGOUT, $entry->action);
        self::assertTrue($userId->equals($entry->entityId ?? throw new \LogicException()));
        self::assertTrue($userId->equals($entry->actorUserId ?? throw new \LogicException()));
        self::assertNull($entry->companyId);
    }
}
