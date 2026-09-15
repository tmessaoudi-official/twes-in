<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Tenancy\Infrastructure;

use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use App\Tenancy\Infrastructure\Security\PermissionVoter;
use App\Tests\Support\InMemoryCurrentCompany;
use App\Tests\Support\InMemoryMemberships;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class PermissionVoterTest extends TestCase
{
    private Company $company;
    private InMemoryMemberships $memberships;
    private InMemoryCurrentCompany $currentCompany;
    private PermissionVoter $voter;

    protected function setUp(): void
    {
        $this->company = new Company('Demo', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships = new InMemoryMemberships();
        $this->currentCompany = new InMemoryCurrentCompany($this->company->getId());
        $this->voter = new PermissionVoter($this->memberships, $this->currentCompany);
    }

    public function testAMemberWhoseRoleListsThePermissionIsGranted(): void
    {
        $user = $this->member(new Role(Role::MEMBER, ['invoice.read', 'invoice.write']));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->token($user), null, ['invoice.write']));
    }

    public function testAMemberWhoseRoleDoesNotListThePermissionIsDenied(): void
    {
        $user = $this->member(new Role(Role::MEMBER, ['invoice.read']));

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($user), null, ['invoice.issue']));
    }

    public function testTheWildcardGrantsEveryCompanyPermission(): void
    {
        $user = $this->member(new Role(Role::OWNER, ['*']));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->token($user), null, ['company.delete']));
    }

    public function testACompanyThatIsNotActiveGrantsItsMembersNothing(): void
    {
        $user = $this->member(new Role(Role::OWNER, ['*']));

        $this->company->suspend();
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($user), null, ['invoice.read']));

        $pending = Company::pending('Waiting', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->memberships->save(new Membership($user, $pending, new Role(Role::OWNER, ['*'])));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($user), $pending, ['invoice.read']));
    }

    public function testAUserWithoutAMembershipInTheCurrentCompanyIsDenied(): void
    {
        $user = new User(Email::fromString('x@example.test'), 'X');

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($user), null, ['invoice.read']));
    }

    public function testWithoutACurrentCompanyEveryCompanyPermissionIsDenied(): void
    {
        $user = $this->member(new Role(Role::OWNER, ['*']));
        $this->currentCompany->set(null);

        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($user), null, ['invoice.read']));
    }

    public function testAnExplicitCompanySubjectOverridesTheSessionCompany(): void
    {
        $user = new User(Email::fromString('m@example.test'), 'M');
        $other = new Company('Other', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->memberships->save(new Membership($user, $other, new Role(Role::MEMBER, ['invoice.read'])));

        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->token($user), $other, ['invoice.read']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($user), null, ['invoice.read']));
    }

    public function testPlatformPermissionsBelongToOperatorsOnlyRegardlessOfMemberships(): void
    {
        $owner = $this->member(new Role(Role::OWNER, ['*']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($owner), null, ['platform.company.create']));

        $operator = new User(Email::fromString('op@example.test'), 'Op');
        $operator->setPlatformOperator(true);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter->vote($this->token($operator), null, ['platform.company.create']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote($this->token($operator), null, ['invoice.read']), 'operator scope is outside memberships: no company permission comes with it');
    }

    public function testAnonymousAndNonPermissionAttributes(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter->vote(new NullToken(), null, ['invoice.read']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote(new NullToken(), null, ['ROLE_USER']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote(new NullToken(), null, ['edit']));
    }

    private function member(Role $role): User
    {
        $user = new User(Email::fromString('m@example.test'), 'M');
        $this->memberships->save(new Membership($user, $this->company, $role));

        return $user;
    }

    private function token(User $user): UsernamePasswordToken
    {
        $account = SecurityUser::of($user);

        return new UsernamePasswordToken($account, 'main', $account->getRoles());
    }
}
