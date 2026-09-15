<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Invitation\DescribeInvitation;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryInvitations;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(DescribeInvitation::class)]
final class DescribeInvitationTest extends TestCase
{
    private const string NOW = '2026-09-09 10:00:00';

    private InMemoryInvitations $invitations;
    private InMemoryUsers $users;

    protected function setUp(): void
    {
        $this->invitations = new InMemoryInvitations();
        $this->users = new InMemoryUsers();
    }

    public function testAUsableLinkDescribesWhatItOffers(): void
    {
        $token = $this->open();

        $summary = $this->describe()->for($token->raw);

        self::assertNotNull($summary);
        self::assertSame('stranger@twes.local', $summary->email);
        self::assertSame('Acme', $summary->companyName);
        self::assertSame(Role::MEMBER, $summary->roleName);
        self::assertFalse($summary->hasAccount);
    }

    public function testALinkForAnAddressWithAnAccountSaysSoSoThePageAsksForNothing(): void
    {
        $token = $this->open();
        $this->users->save(new User(Email::fromString('stranger@twes.local'), 'Already Here'));

        $summary = $this->describe()->for($token->raw);

        self::assertNotNull($summary);
        self::assertTrue($summary->hasAccount);
    }

    public function testAnUnknownLinkDescribesNothing(): void
    {
        self::assertNull($this->describe()->for(InvitationToken::generate()->raw));
    }

    public function testAMalformedLinkDescribesNothing(): void
    {
        self::assertNull($this->describe()->for('not-a-token'));
    }

    public function testAnExpiredLinkDescribesNothing(): void
    {
        $token = $this->open();

        self::assertNull($this->describe('2026-10-01 10:00:00')->for($token->raw));
    }

    public function testAUsedLinkDescribesNothing(): void
    {
        $token = $this->open();
        $this->invitations->invitations[0]->accept(new \DateTimeImmutable(self::NOW));

        self::assertNull($this->describe()->for($token->raw));
    }

    private function open(): InvitationToken
    {
        $token = InvitationToken::generate();
        $this->invitations->save(new Invitation(
            new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'),
            Email::fromString('stranger@twes.local'),
            Role::MEMBER,
            $token,
            new \DateTimeImmutable(self::NOW),
            new \DateInterval('P7D'),
            null,
        ));

        return $token;
    }

    private function describe(string $now = self::NOW): DescribeInvitation
    {
        return new DescribeInvitation($this->invitations, $this->users, new MockClock($now));
    }
}
