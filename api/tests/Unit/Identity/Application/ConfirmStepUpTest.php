<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application;

use App\Identity\Application\Account\DemoAccounts;
use App\Identity\Application\Login\FailedLoginAttempt;
use App\Identity\Application\Login\RecordFailedLogin;
use App\Identity\Application\Mfa\Passkey\PasskeyAssertions;
use App\Identity\Application\PasskeyCeremonies;
use App\Identity\Application\PasswordHasher;
use App\Identity\Application\StepUp\ConfirmStepUp;
use App\Identity\Application\StepUp\StepUpExhausted;
use App\Identity\Application\StepUp\StepUpProofs;
use App\Identity\Application\StepUp\StepUpRefused;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\PasskeyRepository;
use App\Identity\Domain\ValueObject\Email;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryUsers;
use App\Tests\Support\RecordedSecurityNotices;
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
    /** @var list<string> the accounts a demo visitor holds */
    private array $demo = [];

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->user = new User(Email::fromString('someone@example.test'), 'Someone', 'fr');
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

            /** @var array<string, int> */
            private array $failed = [];

            public function failed(Uuid $userId): int
            {
                return $this->failed[$userId->toRfc4122()] = ($this->failed[$userId->toRfc4122()] ?? 0) + 1;
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

    public function testWrongAnswersHereNeverLockTheAccountAndTheFifthEndsTheSignIn(): void
    {
        for ($i = 0; $i < 4; ++$i) {
            try {
                $this->confirm()->withPassword($this->user->getId(), 'guess-'.$i);
                self::fail('a wrong password proved itself');
            } catch (StepUpExhausted) {
                self::fail('four wrong answers spent the budget');
            } catch (StepUpRefused) {
            }
        }
        $this->expectException(StepUpExhausted::class);
        try {
            $this->confirm()->withPassword($this->user->getId(), 'guess-4');
        } finally {
            self::assertSame(0, $this->user->getFailedLoginCount(), 'no step toward the account lock');
            self::assertFalse($this->user->isLockedAt($this->clock->now()));
        }
    }

    /** The number of wrong answers is the deployment's (app.step_up.attempts), not a constant of the code. */
    public function testTheDeploymentsNumberOfAttemptsIsTheOneCounted(): void
    {
        for ($i = 0; $i < 2; ++$i) {
            try {
                $this->confirm(attempts: 3)->withPassword($this->user->getId(), 'guess-'.$i);
                self::fail('a wrong password proved itself');
            } catch (StepUpExhausted) {
                self::fail('two wrong answers spent a budget of three');
            } catch (StepUpRefused) {
            }
        }

        $this->expectException(StepUpExhausted::class);
        $this->confirm(attempts: 3)->withPassword($this->user->getId(), 'guess-2');
    }

    public function testALockedAccountIsRefusedEvenTheRightPasswordUntilTheLockEnds(): void
    {
        $failures = new RecordFailedLogin($this->users, new InMemoryAuditTrail(), new RecordedSecurityNotices(), $this->clock, 5, 'PT15M');
        for ($i = 0; $i < 5; ++$i) {
            $failures->handle(new FailedLoginAttempt($this->user->getId(), 'someone@example.test', 'test', true));
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

    public function testADemoVisitorCountsAsHavingJustProvedItself(): void
    {
        $visitor = Uuid::v7();
        $this->demo = [$visitor->toRfc4122()];

        self::assertTrue($this->confirm()->isRecent($visitor), 'a throwaway account has no password nor passkey to prove itself with');
        self::assertFalse($this->confirm()->isRecent($this->user->getId()), 'everyone else still proves themselves');
    }

    public function testAnotherAccountsProofIsNotThisOnes(): void
    {
        $this->confirm()->withPassword($this->user->getId(), self::PASSWORD);

        self::assertFalse($this->confirm()->isRecent(Uuid::v7()));
    }

    private function confirm(int $attempts = 5): ConfirmStepUp
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
        $demo = $this->demo;
        $demoAccounts = new class($demo) implements DemoAccounts {
            /** @param list<string> $demo */
            public function __construct(private array $demo)
            {
            }

            public function isDemo(Uuid $userId): bool
            {
                return \in_array($userId->toRfc4122(), $this->demo, true);
            }

            public function all(): array
            {
                return array_map(Uuid::fromString(...), $this->demo);
            }
        };

        return new ConfirmStepUp($this->users, $hasher, $assertions, $audit, $this->proofs, $demoAccounts, $this->clock, 'PT5M', $attempts);
    }
}
