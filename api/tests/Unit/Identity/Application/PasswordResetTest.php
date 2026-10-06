<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Password\NewPasswordPolicy;
use App\Identity\Application\Password\NewPasswordRefused;
use App\Identity\Application\Password\RequestPasswordReset;
use App\Identity\Application\Password\ResetLinkNotUsable;
use App\Identity\Application\Password\ResetPassword;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserSession;
use App\Tests\Support\FakeBreachedPasswordCheck;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryPasswordResetMailer;
use App\Tests\Support\InMemoryPasswordResets;
use App\Tests\Support\InMemorySessions;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class PasswordResetTest extends TestCase
{
    private const string NEXT = 'a-brand-new-password';

    private InMemoryUsers $users;
    private InMemoryPasswordResets $resets;
    private InMemoryAuditTrail $audit;
    private FakeTransactions $transactions;
    private MockClock $clock;
    private User $user;
    private InMemoryPasswordResetMailer $mailer;
    private InMemorySessions $sessions;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->resets = new InMemoryPasswordResets();
        $this->sessions = new InMemorySessions();
        $this->mailer = new InMemoryPasswordResetMailer();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->clock = new MockClock('2026-10-02 12:00:00');
        $this->user = new User(Email::fromString('someone@example.test'), 'Someone');
        $this->user->setPasswordHash('hash:old', new \DateTimeImmutable('2026-09-01'));
        $this->users->save($this->user);
    }

    public function testAnAddressWithAnAccountIsMailedALinkThatExpires(): void
    {
        $this->request()->handle(Email::fromString('someone@example.test'), 'fr');

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('someone@example.test', $this->mailer->sent[0]->to);
        self::assertMatchesRegularExpression('#^https://app.test/reset-password/[0-9a-f]{64}$#', $this->mailer->sent[0]->resetUrl);
        self::assertSame(60, $this->mailer->sent[0]->validForMinutes);
        self::assertCount(1, $this->resets->resets);
        self::assertSame(['auth.password_reset_requested'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
    }

    public function testAnUnknownOrDeactivatedAddressGetsNothingAndSaysNothing(): void
    {
        $this->request()->handle(Email::fromString('nobody@example.test'), 'fr');
        $this->user->setActive(false);
        $this->request()->handle(Email::fromString('someone@example.test'), 'fr');

        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->resets->resets);
    }

    public function testAskingAgainReplacesTheOpenLink(): void
    {
        $this->request()->handle(Email::fromString('someone@example.test'), 'fr');
        $this->request()->handle(Email::fromString('someone@example.test'), 'fr');

        self::assertCount(2, $this->mailer->sent);
        self::assertCount(1, $this->resets->resets, 'two live links for one account is one too many');
    }

    public function testTheLinkSetsANewPasswordEndsEverySessionAndIsSpent(): void
    {
        $raw = $this->link();
        $stamp = $this->user->getSecurityStamp();
        $session = new UserSession($this->user, 'a-session-id', 'Firefox on Linux', '192.0.2.1', $this->clock->now());
        $this->sessions->save($session);

        $this->reset()->handle($raw, self::NEXT);
        self::assertTrue($session->isRevoked(), 'the devices list no longer shows a session the new password ended');

        self::assertSame('hash:'.self::NEXT, $this->user->getPasswordHash());
        self::assertNotSame($stamp, $this->user->getSecurityStamp());
        self::assertTrue(array_last($this->resets->resets)?->isUsed(), 'the spent link stays on record, marked used');
        $this->assertNotUsable(fn () => $this->reset()->handle($raw, 'yet-another-password-1'));
        self::assertContains('auth.password_reset', array_map(static fn ($entry): string => $entry->action, $this->audit->entries));
    }

    public function testAnUnknownMalformedOrExpiredLinkIsTheSameNonAnswer(): void
    {
        $raw = $this->link();

        $this->assertNotUsable(fn () => $this->reset()->handle(str_repeat('0', 64), self::NEXT));
        $this->assertNotUsable(fn () => $this->reset()->handle('not-a-token', self::NEXT));

        $this->clock->modify('+61 minutes');
        $this->assertNotUsable(fn () => $this->reset()->handle($raw, self::NEXT));
        self::assertSame('hash:old', $this->user->getPasswordHash());
    }

    public function testTwoUsesOfOneLinkAtOnceSetOnePasswordNotTwo(): void
    {
        // The other request spent the link between this one's first read and its lock (audit 2026-10-06, C-F6).
        $raw = $this->link();
        $this->resets->whileWaitingForTheLock = function (): void {
            array_last($this->resets->resets)?->markUsed($this->clock->now());
            $this->user->setPasswordHash('hash:the-other-request', $this->clock->now());
        };

        $this->assertNotUsable(fn () => $this->reset()->handle($raw, self::NEXT));
        self::assertSame('hash:the-other-request', $this->user->getPasswordHash());
    }

    public function testAPasswordThePolicyRefusesLeavesTheLinkUsable(): void
    {
        $raw = $this->link();

        try {
            $this->reset()->handle($raw, 'short');
            self::fail('a short password was accepted');
        } catch (NewPasswordRefused $refused) {
            self::assertSame(NewPasswordRefused::TOO_SHORT, $refused->reason);
        }

        $this->reset()->handle($raw, self::NEXT);
        self::assertSame('hash:'.self::NEXT, $this->user->getPasswordHash());
    }

    private function link(): string
    {
        $this->request()->handle(Email::fromString('someone@example.test'), 'fr');

        $mail = array_last($this->mailer->sent);
        self::assertNotNull($mail);

        return substr($mail->resetUrl, -64);
    }

    private function request(): RequestPasswordReset
    {
        return new RequestPasswordReset($this->users, $this->resets, $this->mailer, $this->audit, $this->transactions, $this->clock, 'https://app.test/reset-password/{token}', 'PT1H');
    }

    private function reset(): ResetPassword
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

        return new ResetPassword($this->users, $this->resets, $hasher, new NewPasswordPolicy(new FakeBreachedPasswordCheck(false)), $this->audit, $this->transactions, $this->clock, $this->sessions);
    }

    private function assertNotUsable(callable $act): void
    {
        try {
            $act();
            self::fail('the link was accepted');
        } catch (ResetLinkNotUsable) {
            $this->addToAssertionCount(1);
        }
    }
}
