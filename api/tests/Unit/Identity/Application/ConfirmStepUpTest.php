<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Login\PasswordAttempts;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Application\Mfa\PasskeyAssertions;
use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Application\PasswordHasher;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpProofs;
use App\Identity\Application\StepUp\StepUpRefused;
use App\Identity\Domain\Email;
use App\Identity\Domain\PasskeyRepository;
use App\Identity\Domain\User;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/**
 * What a confirmed proof opens is the next few minutes, not the rest of the sign-in (docs/SPEC.md § 7, audit H-b2): an
 * export asks for it, and a screen left open after an export must not hand out the next file to whoever sits down.
 */
final class ConfirmStepUpTest extends TestCase
{
    private const string PASSWORD = 'the-password';

    private InMemoryUsers $users;
    private User $user;
    private MockClock $clock;
    private StepUpProofs $proofs;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->user = new User(Email::fromString('someone@example.test'), 'Someone');
        $this->user->setPasswordHash('hash:'.self::PASSWORD, new \DateTimeImmutable('2026-09-01'));
        $this->users->save($this->user);
        $this->clock = new MockClock('2026-10-06 09:00:00');
        $this->proofs = new class implements StepUpProofs {
            /** @var array<string, \DateTimeImmutable> */
            private array $at = [];

            public function remember(Uuid $userId, \DateTimeImmutable $at): void
            {
                $this->at[$userId->toRfc4122()] = $at;
            }

            public function lastFor(Uuid $userId): ?\DateTimeImmutable
            {
                return $this->at[$userId->toRfc4122()] ?? null;
            }

            public function forget(): void
            {
                $this->at = [];
            }
        };
    }

    public function testNothingIsRecentBeforeAProof(): void
    {
        self::assertFalse($this->confirm()->isRecent($this->user->getId()));
    }

    public function testAProofOpensTheNextFiveMinutesAndNotOneSecondMore(): void
    {
        $this->confirm()->withPassword($this->user->getId(), self::PASSWORD);

        $this->clock->sleep(300);
        self::assertTrue($this->confirm()->isRecent($this->user->getId()), 'five minutes on, still open');
        $this->clock->sleep(1);
        self::assertFalse($this->confirm()->isRecent($this->user->getId()), 'a second past five minutes, closed');
    }

    public function testARefusedProofOpensNothing(): void
    {
        try {
            $this->confirm()->withPassword($this->user->getId(), 'not-it');
            self::fail('the wrong password was accepted');
        } catch (StepUpRefused) {
        }

        self::assertFalse($this->confirm()->isRecent($this->user->getId()));
    }

    public function testALockedAccountIsRefusedEvenTheRightPasswordUntilTheLockEnds(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            try {
                $this->confirm()->withPassword($this->user->getId(), 'guess-'.$i);
            } catch (StepUpRefused) {
            }
        }

        try {
            $this->confirm()->withPassword($this->user->getId(), self::PASSWORD);
            self::fail('a locked account proved itself');
        } catch (StepUpRefused) {
        }
        self::assertSame(5, $this->user->getFailedLoginCount(), 'a refusal because of the lock is not one more guess');

        $this->clock->modify('+16 minutes');
        $this->confirm()->withPassword($this->user->getId(), self::PASSWORD);
        self::assertTrue($this->confirm()->isRecent($this->user->getId()));
    }

    public function testAnotherAccountsProofIsNotThisOnes(): void
    {
        $this->confirm()->withPassword($this->user->getId(), self::PASSWORD);

        self::assertFalse($this->confirm()->isRecent(Uuid::v7()));
    }

    private function confirm(): ConfirmStepUp
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
        $assertions = new PasskeyAssertions($this->createStub(PasskeyRepository::class), $this->createStub(PasskeyCeremonies::class));

        $audit = new InMemoryAuditTrail();

        return new ConfirmStepUp($this->users, new PasswordAttempts($hasher, new RecordFailedLogin($this->users, $audit, $this->clock, 5, 'PT15M'), $this->clock, new FakeTransactions()), $assertions, $audit, $this->proofs, $this->clock, 'PT5M');
    }
}
