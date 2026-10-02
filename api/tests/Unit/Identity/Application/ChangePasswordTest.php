<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Password\ChangePassword;
use App\Identity\Application\Password\NewPasswordRefused;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tests\Support\FakeBreachedPasswordCheck;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ChangePasswordTest extends TestCase
{
    private const string CURRENT = 'the-current-password';
    private const string NEXT = 'a-brand-new-password';

    private InMemoryUsers $users;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
    private User $user;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->user = new User(Email::fromString('someone@example.test'), 'Someone');
        $this->user->setPasswordHash('hash:'.self::CURRENT, new \DateTimeImmutable('2026-09-01'));
        $this->users->save($this->user);
    }

    public function testTheNewPasswordReplacesTheOldOneAndEndsEverySession(): void
    {
        $stamp = $this->user->getSecurityStamp();

        $this->change()->handle($this->user->getId(), self::CURRENT, self::NEXT);

        self::assertSame('hash:'.self::NEXT, $this->user->getPasswordHash());
        self::assertNotSame($stamp, $this->user->getSecurityStamp(), 'every session opened with the old password ends');
        self::assertSame(['auth.password_changed'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
    }

    public function testAWrongCurrentPasswordChangesNothingAndIsAudited(): void
    {
        $this->assertRefused(NewPasswordRefused::CURRENT_PASSWORD, fn () => $this->change()->handle($this->user->getId(), 'not-it', self::NEXT));

        self::assertSame('hash:'.self::CURRENT, $this->user->getPasswordHash());
        self::assertSame(['auth.password_change_refused'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
    }

    public function testAPasswordUnderTwelveCharactersIsRefused(): void
    {
        $this->assertRefused(NewPasswordRefused::TOO_SHORT, fn () => $this->change()->handle($this->user->getId(), self::CURRENT, 'short-one'));
    }

    public function testTheSamePasswordIsNotAChange(): void
    {
        $this->assertRefused(NewPasswordRefused::UNCHANGED, fn () => $this->change()->handle($this->user->getId(), self::CURRENT, self::CURRENT));
    }

    public function testABreachedPasswordIsRefusedAndAnUnreachableServiceIsAuditedButAccepted(): void
    {
        $this->assertRefused(NewPasswordRefused::BREACHED, fn () => $this->change(true)->handle($this->user->getId(), self::CURRENT, self::NEXT));

        $this->change(null)->handle($this->user->getId(), self::CURRENT, self::NEXT);

        self::assertSame('hash:'.self::NEXT, $this->user->getPasswordHash());
        self::assertContains('auth.password_breach_check_skipped', array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
    }

    private function change(?bool $breached = false): ChangePassword
    {
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string
            {
                return 'hash:'.$plainPassword;
            }

            public function verify(string $hash, string $plainPassword): bool
            {
                return 'hash:'.$plainPassword === $hash;
            }
        };

        return new ChangePassword($this->users, $hasher, new FakeBreachedPasswordCheck($breached), $this->audit, $this->transactions, new MockClock('2026-10-02 12:00:00'));
    }

    private function assertRefused(string $reason, callable $act): void
    {
        try {
            $act();
            self::fail('the change was accepted');
        } catch (NewPasswordRefused $refused) {
            self::assertSame($reason, $refused->reason);
        }
    }
}
