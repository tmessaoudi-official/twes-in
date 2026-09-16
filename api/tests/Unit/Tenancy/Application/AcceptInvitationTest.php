<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Application;

use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\AcceptRequest;
use App\Tenancy\Application\Invitation\AccountDetailsRequired;
use App\Tenancy\Application\Invitation\InvitationNotUsable;
use App\Tenancy\Application\Invitation\PasswordBreached;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tests\Support\FakeBreachedPasswordCheck;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCompanies;
use App\Tests\Support\InMemoryInvitations;
use App\Tests\Support\InMemoryMemberships;
use App\Tests\Support\InMemoryNotifications;
use App\Tests\Support\InMemoryRoles;
use App\Tests\Support\InMemoryUsers;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(AcceptInvitation::class)]
final class AcceptInvitationTest extends TestCase
{
    private const string NOW = '2026-09-09 10:00:00';
    private const string PASSWORD = 'a-long-enough-password';

    private InMemoryUsers $users;
    private InMemoryMemberships $memberships;
    private InMemoryInvitations $invitations;
    private InMemoryCompanies $companies;
    private InMemoryNotifications $notifications;
    private FakeTransactions $transactions;
    private InMemoryAuditTrail $audit;
    private InMemoryRoles $roles;
    private Company $company;

    protected function setUp(): void
    {
        $this->users = new InMemoryUsers();
        $this->memberships = new InMemoryMemberships();
        $this->invitations = new InMemoryInvitations();
        $this->companies = new InMemoryCompanies();
        $this->notifications = new InMemoryNotifications();
        $this->transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($this->transactions);
        $this->roles = new InMemoryRoles();
        $this->roles->save(new Role(Role::OWNER, [Permission::WILDCARD]));
        $this->roles->save(new Role(Role::MEMBER, ['company.read']));
        $this->company = Company::pending('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->companies->save($this->company);
    }

    public function testAcceptingCreatesTheAccountAndTheMembership(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $outcome = $this->accept()->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        $user = $this->users->ofEmail(Email::fromString('stranger@twes.local'));
        self::assertNotNull($user);
        self::assertSame('New Person', $user->getDisplayName());
        self::assertSame('hashed:'.self::PASSWORD, $user->getPasswordHash());
        self::assertTrue($user->isActive());
        self::assertFalse($user->isPlatformOperator());
        self::assertNotNull($this->memberships->ofUserInCompany($user->getId(), $this->company->getId()));
        self::assertSame($user->getId()->toRfc4122(), $outcome->userId);
    }

    public function testTheInvitationIsUsedUp(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $this->accept()->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        self::assertNotNull($this->invitations->invitations[0]->getAcceptedAt());
    }

    public function testTheSameLinkCannotBeUsedTwice(): void
    {
        $token = $this->openInvitation(Role::MEMBER);
        $this->accept()->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        $this->expectException(InvitationNotUsable::class);
        $this->accept()->handle(new AcceptRequest($token->raw, 'Someone Else', self::PASSWORD));
    }

    public function testAnOwnerAcceptingActivatesThePendingCompany(): void
    {
        $token = $this->openInvitation(Role::OWNER);

        $this->accept()->handle(new AcceptRequest($token->raw, 'The Owner', self::PASSWORD));

        self::assertSame(Company::STATUS_ACTIVE, $this->company->getStatus());
    }

    public function testAPlainMemberAcceptingLeavesTheCompanyPending(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $this->accept()->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        self::assertSame(Company::STATUS_PENDING, $this->company->getStatus());
    }

    public function testAnUnknownTokenIsRefused(): void
    {
        $this->expectException(InvitationNotUsable::class);
        $this->accept()->handle(new AcceptRequest(InvitationToken::generate()->raw, 'X', self::PASSWORD));
    }

    public function testAMalformedTokenIsRefusedTheSameWay(): void
    {
        $this->expectException(InvitationNotUsable::class);
        $this->accept()->handle(new AcceptRequest('not-a-token', 'X', self::PASSWORD));
    }

    public function testAnExpiredTokenIsRefusedTheSameWay(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $this->expectException(InvitationNotUsable::class);
        $this->accept('2026-10-01 10:00:00')->handle(new AcceptRequest($token->raw, 'X', self::PASSWORD));
    }

    public function testABreachedPasswordIsRefusedAndNothingIsCreated(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        try {
            $this->accept(breached: true)->handle(new AcceptRequest($token->raw, 'X', self::PASSWORD));
            self::fail('expected PasswordBreached');
        } catch (PasswordBreached) {
        }

        self::assertNull($this->users->ofEmail(Email::fromString('stranger@twes.local')));
        self::assertNull($this->invitations->invitations[0]->getAcceptedAt());
    }

    public function testAnUnreachableBreachServiceAcceptsThePasswordAndRecordsTheSkip(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $this->accept(breached: null)->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        self::assertNotNull($this->users->ofEmail(Email::fromString('stranger@twes.local')));
        $actions = array_map(static fn ($e) => $e->action, $this->audit->entries);
        self::assertContains(AcceptInvitation::BREACH_CHECK_SKIPPED, $actions);
    }

    public function testAWorkingBreachServiceRecordsNoSkip(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $this->accept()->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        $actions = array_map(static fn ($e) => $e->action, $this->audit->entries);
        self::assertNotContains(AcceptInvitation::BREACH_CHECK_SKIPPED, $actions);
    }

    public function testAnAddressThatGainedAnAccountMeanwhileJoinsWithoutItsPasswordBeingTouched(): void
    {
        $token = $this->openInvitation(Role::MEMBER);
        $existing = new User(Email::fromString('stranger@twes.local'), 'Already Here');
        $existing->setPasswordHash('their-own-hash', new \DateTimeImmutable(self::NOW));
        $this->users->save($existing);

        $outcome = $this->accept()->handle(new AcceptRequest($token->raw, 'Impostor', self::PASSWORD));

        // A mailed link must never be able to set the password of an account that already exists.
        self::assertSame('their-own-hash', $existing->getPasswordHash());
        self::assertSame('Already Here', $existing->getDisplayName());
        self::assertFalse($outcome->passwordSet);
        self::assertNotNull($this->memberships->ofUserInCompany($existing->getId(), $this->company->getId()));
    }

    public function testAnExistingAccountAcceptsWithNothingFilledIn(): void
    {
        $token = $this->openInvitation(Role::MEMBER);
        $existing = new User(Email::fromString('stranger@twes.local'), 'Already Here');
        $existing->setPasswordHash('their-own-hash', new \DateTimeImmutable(self::NOW));
        $this->users->save($existing);

        $outcome = $this->accept()->handle(new AcceptRequest($token->raw, null, null));

        self::assertSame($existing->getId()->toRfc4122(), $outcome->userId);
        self::assertSame('their-own-hash', $existing->getPasswordHash());
        self::assertNotNull($this->memberships->ofUserInCompany($existing->getId(), $this->company->getId()));
    }

    public function testANewAccountStillNeedsANameAndAPasswordAndNothingIsUsedUpWithout(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        foreach ([[null, self::PASSWORD], ['New Person', null]] as [$name, $password]) {
            try {
                $this->accept()->handle(new AcceptRequest($token->raw, $name, $password));
                self::fail('an account was made without a name and a password');
            } catch (AccountDetailsRequired) {
            }
        }

        self::assertNull($this->users->ofEmail(Email::fromString('stranger@twes.local')));
        self::assertNull($this->invitations->invitations[0]->getAcceptedAt());
    }

    public function testAcceptingIsAudited(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $this->accept()->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        $actions = array_map(static fn ($e) => $e->action, $this->audit->entries);
        self::assertContains(AcceptInvitation::ACCEPTED, $actions);
    }

    public function testTheCompanyIsToldSomebodyJoined(): void
    {
        $token = $this->openInvitation(Role::MEMBER);

        $this->accept()->handle(new AcceptRequest($token->raw, 'New Person', self::PASSWORD));

        self::assertCount(1, $this->notifications->published);
        self::assertSame('company:'.$this->company->getId()->toRfc4122(), $this->notifications->published[0]->channel);
    }

    private function openInvitation(string $roleName): InvitationToken
    {
        $token = InvitationToken::generate();
        $this->invitations->save(new Invitation(
            $this->company,
            Email::fromString('stranger@twes.local'),
            $roleName,
            $token,
            new \DateTimeImmutable(self::NOW),
            new \DateInterval('P7D'),
            null,
        ));

        return $token;
    }

    private function accept(string $now = self::NOW, ?bool $breached = false): AcceptInvitation
    {
        $hasher = new class implements PasswordHasher {
            public function hash(string $plainPassword): string
            {
                return 'hashed:'.$plainPassword;
            }
        };

        return new AcceptInvitation(
            $this->invitations,
            $this->users,
            $this->memberships,
            $this->roles,
            $this->companies,
            $hasher,
            new FakeBreachedPasswordCheck($breached),
            $this->notifications,
            $this->audit,
            new MockClock($now),
            $this->transactions,
        );
    }
}
