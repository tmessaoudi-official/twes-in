<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Scanning\Domain;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Scanning\Domain\ScanPairing;
use App\Scanning\Domain\ScanPairingRefused;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A phone paired to one computer tab (docs/SPEC.md § 7, 2026-09-23 09:45, slice 4): a single-use link claimed once,
 * then a key the phone presents with each scan, alive only while the computer's tab keeps saying it is there.
 */
#[CoversClass(ScanPairing::class)]
final class ScanPairingTest extends TestCase
{
    private const string LINK = 'link-token-0123456789abcdef0123456789abcdef';
    private const string KEY = 'phone-key-0123456789abcdef0123456789abcdef';

    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-23 12:00:00');
    }

    public function testTheLinkIsClaimedOnceAndTheKeyThenAuthorises(): void
    {
        $pairing = $this->pairing();

        $pairing->claim(self::KEY, $this->at(10));

        self::assertTrue($pairing->isClaimed());
        $pairing->authorise(self::KEY, $this->at(20));
        $this->refused('claimed', fn () => $pairing->claim('another-key-0123456789abcdef0123456789ab', $this->at(30)));
    }

    public function testNeitherTheLinkNorTheKeyIsKeptInClear(): void
    {
        $pairing = $this->pairing();
        $pairing->claim(self::KEY, $this->at(10));

        self::assertSame(hash('sha256', self::LINK), $pairing->getLinkHash());
        self::assertSame(ScanPairing::linkHash(self::LINK), $pairing->getLinkHash());
        self::assertNotSame(self::KEY, $pairing->getKeyHash());
    }

    public function testAnotherKeyOrAnUnclaimedPairingAuthorisesNothing(): void
    {
        $pairing = $this->pairing();
        $this->refused('key', fn () => $pairing->authorise(self::KEY, $this->at(5)));

        $pairing->claim(self::KEY, $this->at(10));
        $this->refused('key', fn () => $pairing->authorise('wrong-key-0123456789abcdef0123456789abcd', $this->at(20)));
    }

    public function testTheLinkMustBeClaimedWithinItsWindow(): void
    {
        $pairing = $this->pairing();
        $pairing->renew($this->at(60));
        $pairing->renew($this->at(120));
        $pairing->renew($this->at(180));
        $pairing->renew($this->at(240));
        $pairing->renew($this->at(290));

        $this->refused('expired', fn () => $pairing->claim(self::KEY, $this->at(ScanPairing::CLAIM_WITHIN_SECONDS + 1)));
    }

    public function testThePairingLapsesWhenTheComputerStopsSayingItIsThere(): void
    {
        $pairing = $this->pairing();
        $pairing->claim(self::KEY, $this->at(10));

        $pairing->renew($this->at(80));
        $pairing->authorise(self::KEY, $this->at(80 + ScanPairing::ALIVE_SECONDS));

        self::assertFalse($pairing->isLive($this->at(81 + ScanPairing::ALIVE_SECONDS)));
        $this->refused('ended', fn () => $pairing->authorise(self::KEY, $this->at(81 + ScanPairing::ALIVE_SECONDS)));
        $this->refused('ended', fn () => $pairing->renew($this->at(81 + ScanPairing::ALIVE_SECONDS)));
    }

    public function testAnEndedPairingStaysEnded(): void
    {
        $pairing = $this->pairing();
        $pairing->claim(self::KEY, $this->at(10));

        $pairing->end($this->at(20));
        $pairing->end($this->at(30));

        self::assertEquals($this->at(20), $pairing->getEndedAt());
        self::assertFalse($pairing->isLive($this->at(21)));
        $this->refused('ended', fn () => $pairing->authorise(self::KEY, $this->at(21)));
        $this->refused('ended', fn () => $pairing->renew($this->at(21)));
    }

    public function testAnEndedLinkCannotBeClaimed(): void
    {
        $pairing = $this->pairing();
        $pairing->end($this->at(5));

        $this->refused('ended', fn () => $pairing->claim(self::KEY, $this->at(10)));
    }

    public function testATabIsANameOfAtMostSixtyFourCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScanPairing($this->company(), $this->user(), str_repeat('t', 65), self::LINK, $this->now);
    }

    private function pairing(): ScanPairing
    {
        return new ScanPairing($this->company(), $this->user(), 'tab-1', self::LINK, $this->now);
    }

    private function refused(string $reason, \Closure $act): void
    {
        try {
            $act();
        } catch (ScanPairingRefused $refused) {
            self::assertSame($reason, $refused->reason);

            return;
        }
        self::fail("expected a refusal: $reason");
    }

    private function at(int $seconds): \DateTimeImmutable
    {
        return $this->now->modify("+$seconds seconds");
    }

    private function company(): Company
    {
        return new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    private function user(): User
    {
        return new User(Email::fromString('amel@twes.local'), 'Amel');
    }
}
