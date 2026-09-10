<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use PHPUnit\Framework\TestCase;

/**
 * The second factor as a state machine. Enrolment is two steps on purpose: a secret that became active the
 * moment it was generated would lock out anyone whose authenticator mis-scanned the QR code, with the
 * factor enabled and no way to satisfy it.
 *
 * The stored secret is ciphertext throughout — the domain never sees the plaintext, and never encrypts:
 * the use case does that through the `SecretCipher` port.
 */
final class UserMfaTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        $this->user = new User(Email::fromString('someone@twes.local'), 'Someone');
    }

    public function testAFreshUserHasNoSecondFactor(): void
    {
        self::assertFalse($this->user->hasTotp());
        self::assertNull($this->user->getTotpSecret());
    }

    public function testAPendingEnrolmentIsNotYetASecondFactor(): void
    {
        $this->user->beginTotpEnrolment('ciphertext');

        self::assertFalse($this->user->hasTotp());
        self::assertSame('ciphertext', $this->user->getTotpSecret());
    }

    public function testConfirmingTheEnrolmentTurnsItOn(): void
    {
        $this->user->beginTotpEnrolment('ciphertext');
        $this->user->confirmTotpEnrolment(58582080);

        self::assertTrue($this->user->hasTotp());
    }

    public function testConfirmingWithNothingPendingIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->user->confirmTotpEnrolment(58582080);
    }

    public function testAConfirmedCodeCannotBeUsedASecondTime(): void
    {
        // The whole point of carrying the timestep: within its window the code stays arithmetically valid,
        // so replay is refusable only by remembering which step was already spent.
        $this->user->beginTotpEnrolment('ciphertext');
        $this->user->confirmTotpEnrolment(58582080);

        $this->expectException(\DomainException::class);
        $this->user->useTotpTimestep(58582080);
    }

    public function testAnOlderTimestepIsRefusedToo(): void
    {
        $this->user->beginTotpEnrolment('ciphertext');
        $this->user->confirmTotpEnrolment(58582080);

        $this->expectException(\DomainException::class);
        $this->user->useTotpTimestep(58582079);
    }

    public function testTheNextTimestepIsAccepted(): void
    {
        $this->user->beginTotpEnrolment('ciphertext');
        $this->user->confirmTotpEnrolment(58582080);

        $this->user->useTotpTimestep(58582081);

        self::assertSame(58582081, $this->user->getTotpLastTimestep());
    }

    public function testUsingAFactorThatIsNotOnIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->user->useTotpTimestep(58582081);
    }

    public function testDisablingClearsEverySignOfIt(): void
    {
        $this->user->beginTotpEnrolment('ciphertext');
        $this->user->confirmTotpEnrolment(58582080);

        $this->user->disableTotp();

        self::assertFalse($this->user->hasTotp());
        self::assertNull($this->user->getTotpSecret());
        self::assertNull($this->user->getTotpLastTimestep());
    }

    public function testReEnrollingReplacesTheSecretAndStartsPendingAgain(): void
    {
        $this->user->beginTotpEnrolment('first');
        $this->user->confirmTotpEnrolment(58582080);

        $this->user->beginTotpEnrolment('second');

        self::assertFalse($this->user->hasTotp(), 'the old factor stops counting the moment a new one is begun');
        self::assertSame('second', $this->user->getTotpSecret());
        self::assertNull($this->user->getTotpLastTimestep());
    }
}
