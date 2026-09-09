<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Domain;

use App\Identity\Domain\Email;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\Role;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Invitation::class)]
#[CoversClass(InvitationToken::class)]
final class InvitationTest extends TestCase
{
    private const string NOW = '2026-09-09 10:00:00';

    public function testTheRawTokenIsNeverStored(): void
    {
        $token = InvitationToken::generate();

        $invitation = $this->invite($token);

        self::assertSame(hash('sha256', $token->raw), $invitation->getTokenHash());
        self::assertStringNotContainsString($token->raw, serialize($invitation));
    }

    public function testTwoGeneratedTokensDiffer(): void
    {
        self::assertNotSame(InvitationToken::generate()->raw, InvitationToken::generate()->raw);
    }

    public function testAGeneratedTokenIsLongEnoughToResistGuessing(): void
    {
        // 32 random bytes, hex encoded.
        self::assertSame(64, \strlen(InvitationToken::generate()->raw));
    }

    public function testAMalformedTokenIsRefusedRatherThanHashed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        InvitationToken::fromRaw('not-a-token');
    }

    public function testAFreshInvitationIsUsable(): void
    {
        self::assertTrue($this->invite()->isUsableAt(new \DateTimeImmutable(self::NOW)));
    }

    public function testAnInvitationIsNoLongerUsableAfterItExpires(): void
    {
        $invitation = $this->invite();

        self::assertFalse($invitation->isUsableAt(new \DateTimeImmutable('2026-09-17 10:00:01')));
    }

    public function testAnInvitationIsStillUsableTheInstantItExpires(): void
    {
        self::assertTrue($this->invite()->isUsableAt(new \DateTimeImmutable('2026-09-16 10:00:00')));
    }

    public function testAcceptingMarksItUsed(): void
    {
        $invitation = $this->invite();
        $at = new \DateTimeImmutable(self::NOW);

        $invitation->accept($at);

        self::assertSame($at, $invitation->getAcceptedAt());
        self::assertFalse($invitation->isUsableAt($at));
    }

    public function testAnInvitationIsUsedOnlyOnce(): void
    {
        $invitation = $this->invite();
        $at = new \DateTimeImmutable(self::NOW);
        $invitation->accept($at);

        $this->expectException(\DomainException::class);
        $invitation->accept($at);
    }

    public function testAnExpiredInvitationCannotBeAccepted(): void
    {
        $invitation = $this->invite();

        $this->expectException(\DomainException::class);
        $invitation->accept(new \DateTimeImmutable('2026-10-01 10:00:00'));
    }

    public function testItRemembersWhatItInvitesTo(): void
    {
        $invitation = $this->invite();

        self::assertSame('joiner@twes.local', $invitation->getEmail()->value);
        self::assertSame(Role::MEMBER, $invitation->getRoleName());
        self::assertSame('Acme', $invitation->getCompany()->getName());
    }

    private function invite(?InvitationToken $token = null): Invitation
    {
        return new Invitation(
            new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'),
            Email::fromString('joiner@twes.local'),
            Role::MEMBER,
            $token ?? InvitationToken::generate(),
            new \DateTimeImmutable(self::NOW),
            new \DateInterval('P7D'),
            null,
        );
    }
}
