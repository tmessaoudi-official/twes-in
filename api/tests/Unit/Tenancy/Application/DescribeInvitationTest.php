<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Tenancy\Application\Invitation\DescribeInvitation;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Invitation;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\ValueObject\InvitationToken;
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
        $this->users->save(new User(Email::fromString('stranger@twes.local'), 'Already Here', 'fr'));

        $summary = $this->describe()->for($token->raw);

        self::assertNotNull($summary);
        self::assertTrue($summary->hasAccount);
    }

    public function testAnOpenCompanyIsSaidToBeNeitherRefusedNorSuspended(): void
    {
        $summary = $this->describe()->for($this->open()->raw);
        self::assertNotNull($summary);
        self::assertSame([false, false, null], [$summary->companyRefused, $summary->companySuspended, $summary->companyReason]);
    }

    public function testACompanyRefusedBeforeItsOwnerJoinedIsSaidRefusedWithItsReasonBeforeTheFormIsFilled(): void
    {
        $company = Company::pending('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis', new \DateTimeImmutable(self::NOW));
        $company->suspend(new \DateTimeImmutable(self::NOW), 'Matricule fiscal introuvable');
        $summary = $this->describe()->for($this->open($company)->raw);

        self::assertNotNull($summary);
        self::assertSame([true, false, 'Matricule fiscal introuvable'], [$summary->companyRefused, $summary->companySuspended, $summary->companyReason]);
    }

    public function testASuspendedCompanyIsSaidSuspendedWithItsReason(): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $company->suspend(new \DateTimeImmutable(self::NOW), 'Règlement en attente');
        $summary = $this->describe()->for($this->open($company)->raw);

        self::assertNotNull($summary);
        self::assertSame([false, true, 'Règlement en attente'], [$summary->companyRefused, $summary->companySuspended, $summary->companyReason]);
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

    private function open(?Company $company = null): InvitationToken
    {
        $token = InvitationToken::generate();
        $this->invitations->save(new Invitation(
            $company ?? new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'),
            Email::fromString('stranger@twes.local'),
            new Role(Role::MEMBER, []),
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
