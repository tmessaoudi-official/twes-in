<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Company\AddMember;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Application\Invitation\InviteRequest;
use App\Tenancy\Application\Invitation\InviteToCompany;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCompanies;
use App\Tests\Support\InMemoryInvitationMailer;
use App\Tests\Support\InMemoryInvitations;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryNotifications;
use App\Tests\Support\InMemoryRoles;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(InviteToCompany::class)]
final class InviteToCompanyTest extends TestCase
{
    private InMemoryCompanies $companies;
    private InMemoryUsers $users;
    private InMemoryMemberships $memberships;
    private InMemoryInvitations $invitations;
    private InMemoryInvitationMailer $mailer;
    private InMemoryNotifications $notifications;
    private InMemoryAuditTrail $audit;
    private InviteToCompany $invite;
    private Company $company;

    protected function setUp(): void
    {
        $this->companies = new InMemoryCompanies();
        $this->users = new InMemoryUsers();
        $this->memberships = new InMemoryMemberships();
        $this->invitations = new InMemoryInvitations();
        $this->mailer = new InMemoryInvitationMailer();
        $this->notifications = new InMemoryNotifications();
        $this->audit = new InMemoryAuditTrail();
        $roles = new InMemoryRoles();
        $roles->save(new Role(Role::OWNER, [Permission::WILDCARD]));
        $roles->save(new Role(Role::MEMBER, ['company.read']));
        $clock = new MockClock('2026-09-09 10:00:00');

        $addMember = new AddMember($this->companies, $this->users, $this->memberships, $roles, $this->audit, $clock);
        $this->invite = new InviteToCompany(
            $this->companies,
            $this->users,
            $roles,
            $this->invitations,
            $addMember,
            $this->mailer,
            $this->notifications,
            $this->audit,
            $clock,
            'https://twes.test/invitations/{token}',
            'P7D',
        );

        $this->company = Company::pending('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->companies->save($this->company);
    }

    public function testAnAddressThatAlreadyHasAnAccountJoinsWithNoMailAndNoInvitation(): void
    {
        $user = new User(Email::fromString('joiner@twes.local'), 'Joiner');
        $this->users->save($user);

        $outcome = $this->invite->handle($this->request('joiner@twes.local'), null);

        self::assertTrue($outcome->joined);
        self::assertSame([], $this->invitations->invitations);
        self::assertSame([], $this->mailer->sent);
        self::assertNotNull($this->memberships->ofUserInCompany($user->getId(), $this->company->getId()));
    }

    public function testSomeoneWhoJoinedDirectlyIsToldAboutIt(): void
    {
        $user = new User(Email::fromString('joiner@twes.local'), 'Joiner');
        $this->users->save($user);

        $this->invite->handle($this->request('joiner@twes.local'), null);

        self::assertCount(1, $this->notifications->published);
        self::assertSame('user:'.$user->getId()->toRfc4122(), $this->notifications->published[0]->channel);
        self::assertSame('membership.added', $this->notifications->published[0]->type);
    }

    public function testAnAddressWithNoAccountIsInvitedByMail(): void
    {
        $outcome = $this->invite->handle($this->request('stranger@twes.local'), null);

        self::assertFalse($outcome->joined);
        self::assertCount(1, $this->invitations->invitations);
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('stranger@twes.local', $this->mailer->sent[0]->to);
        self::assertSame('Acme', $this->mailer->sent[0]->companyName);
    }

    public function testTheMailCarriesTheRawTokenAndTheStoreOnlyItsHash(): void
    {
        $this->invite->handle($this->request('stranger@twes.local'), null);

        $url = $this->mailer->sent[0]->acceptUrl;
        self::assertMatchesRegularExpression('#^https://twes\.test/invitations/[0-9a-f]{64}$#', $url);
        $raw = substr($url, strrpos($url, '/') + 1);
        self::assertSame(hash('sha256', $raw), $this->invitations->invitations[0]->getTokenHash());
    }

    public function testTheInvitationExpires(): void
    {
        $this->invite->handle($this->request('stranger@twes.local'), null);

        self::assertEquals(
            new \DateTimeImmutable('2026-09-16 10:00:00'),
            $this->invitations->invitations[0]->getExpiresAt(),
        );
    }

    public function testInvitingAgainReplacesTheOpenInvitationSoOnlyOneTokenWorks(): void
    {
        $this->invite->handle($this->request('stranger@twes.local'), null);
        $first = $this->invitations->invitations[0]->getTokenHash();

        $this->invite->handle($this->request('stranger@twes.local'), null);

        self::assertCount(1, $this->invitations->invitations);
        self::assertNotSame($first, $this->invitations->invitations[0]->getTokenHash());
    }

    public function testTheAddressIsNormalisedTheWayEmailsAre(): void
    {
        $this->invite->handle($this->request('  STRANGER@Twes.Local '), null);

        self::assertSame('stranger@twes.local', $this->invitations->invitations[0]->getEmail()->value);
    }

    public function testAnUnknownCompanyIsRefusedBeforeAnythingIsSent(): void
    {
        try {
            $this->invite->handle(new InviteRequest(Uuid::v7(), 'stranger@twes.local', Role::MEMBER), null);
            self::fail('expected CompanyNotFound');
        } catch (CompanyNotFound) {
        }

        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->invitations->invitations);
    }

    public function testAnInventedRoleIsRefusedBeforeAnythingIsSent(): void
    {
        try {
            $this->invite->handle(new InviteRequest($this->company->getId(), 'stranger@twes.local', 'emperor'), null);
            self::fail('expected UnknownRole');
        } catch (UnknownRole) {
        }

        self::assertSame([], $this->mailer->sent);
        self::assertSame([], $this->invitations->invitations);
    }

    public function testSendingAnInvitationIsAudited(): void
    {
        $actor = Uuid::v7();

        $this->invite->handle($this->request('stranger@twes.local'), $actor);

        $entry = null;
        foreach ($this->audit->entries as $candidate) {
            if (InviteToCompany::SENT === $candidate->action) {
                $entry = $candidate;
                break;
            }
        }

        self::assertNotNull($entry, 'the invitation was not audited');
        self::assertSame($actor, $entry->actorUserId);
        self::assertSame('stranger@twes.local', $entry->changes['email'] ?? null);
    }

    public function testTheAuditNeverCarriesTheToken(): void
    {
        $this->invite->handle($this->request('stranger@twes.local'), null);

        $raw = substr($this->mailer->sent[0]->acceptUrl, strrpos($this->mailer->sent[0]->acceptUrl, '/') + 1);
        foreach ($this->audit->entries as $entry) {
            self::assertStringNotContainsString($raw, json_encode($entry->changes, \JSON_THROW_ON_ERROR));
        }
    }

    private function request(string $email): InviteRequest
    {
        return new InviteRequest($this->company->getId(), $email, Role::MEMBER);
    }
}
