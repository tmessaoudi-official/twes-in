<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Model\Role;
use Symfony\Component\HttpFoundation\Response;

/**
 * Who must carry a second factor follows where the shop is reached from (docs/SPEC.md § 7, 2026-10-31 21:14, Q5): on its
 * network alone one is offered to everyone and asked of no one by the installation; once its Tailscale name is given,
 * owners and admins are held until they enrol; once its public name is given, everyone is. A company's own requirement
 * still applies on top. The names are the installation's TAILNET_HOST and PUBLIC_HOST, empty while off.
 */
final class ReachSecondFactorTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    /** Each case's names: none (the shop's network alone), the Tailscale one, or the public one beside it. */
    private const array REACH = [
        'testOnTheShopsNetworkAloneNoRoleIsHeld' => ['TAILNET_HOST' => '', 'PUBLIC_HOST' => ''],
        'testWithTailscaleOnOwnersAndAdminsAreHeldUntilTheyEnrol' => ['TAILNET_HOST' => 'shop-pc.tail1234.ts.net', 'PUBLIC_HOST' => ''],
        'testWithTailscaleOnAnAccountHoldingAFactorIsNotHeld' => ['TAILNET_HOST' => 'shop-pc.tail1234.ts.net', 'PUBLIC_HOST' => ''],
        'testWithTailscaleOnAClerkWhoAdministersAnotherCompanyIsHeld' => ['TAILNET_HOST' => 'shop-pc.tail1234.ts.net', 'PUBLIC_HOST' => ''],
        'testWithThePublicNameOnEveryRoleIsHeld' => ['TAILNET_HOST' => 'shop-pc.tail1234.ts.net', 'PUBLIC_HOST' => 'boutique.example.org'],
        'testThePublicNameAloneHoldsEveryRoleToo' => ['TAILNET_HOST' => '', 'PUBLIC_HOST' => 'boutique.example.org'],
    ];

    /** @var array<string, array{env: mixed, server: mixed}> */
    private array $before = [];

    protected function setUp(): void
    {
        foreach (self::REACH[$this->name()] ?? ['TAILNET_HOST' => '', 'PUBLIC_HOST' => ''] as $name => $value) {
            $this->before[$name] = ['env' => $_ENV[$name] ?? null, 'server' => $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        foreach ($this->before as $name => $before) {
            unset($_ENV[$name], $_SERVER[$name]);
            if (null !== $before['env']) {
                $_ENV[$name] = $before['env'];
            }
            if (null !== $before['server']) {
                $_SERVER[$name] = $before['server'];
            }
        }
    }

    public function testOnTheShopsNetworkAloneNoRoleIsHeld(): void
    {
        foreach ([Role::OWNER, Role::ADMIN, Role::MEMBER, Role::CLERK] as $role) {
            self::assertSame([200, false], $this->signedInAs($role), $role);
        }
    }

    public function testWithTailscaleOnOwnersAndAdminsAreHeldUntilTheyEnrol(): void
    {
        self::assertSame([403, true], $this->signedInAs(Role::OWNER));
        self::assertSame([403, true], $this->signedInAs(Role::ADMIN));
        self::assertSame([200, false], $this->signedInAs(Role::MEMBER));
        self::assertSame([200, false], $this->signedInAs(Role::CLERK));
        self::assertSame([200, false], $this->signedInAs(Role::ACCOUNTANT));
    }

    public function testWithTailscaleOnAnAccountHoldingAFactorIsNotHeld(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany(), roleName: Role::OWNER, authenticator: true);
        $this->login('owner@example.test', self::PASSWORD);

        self::assertResponseIsSuccessful();
        $this->getJson('/api/me/companies');
        self::assertResponseIsSuccessful();
    }

    /**
     * An admin of one company who is a clerk of another is held by the first: the requirement is the account's, or the
     * company switcher would be the way around it, as for a company's own requirement.
     */
    public function testWithTailscaleOnAClerkWhoAdministersAnotherCompanyIsHeld(): void
    {
        $shop = $this->createCompany('Boutique');
        $user = $this->createUser('clerk@example.test', self::PASSWORD, $shop, roleName: Role::CLERK, authenticator: false);
        $this->addMembership($user, $this->createCompany('Atelier'), Role::ADMIN);
        $this->login('clerk@example.test', self::PASSWORD);

        $this->getJson('/api/me/companies');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('mfa_enrolment_required', $this->stringAt($this->json(), 'error'));
    }

    public function testWithThePublicNameOnEveryRoleIsHeld(): void
    {
        foreach ([Role::OWNER, Role::ADMIN, Role::MEMBER, Role::CLERK, Role::ACCOUNTANT] as $role) {
            self::assertSame([403, true], $this->signedInAs($role), $role);
        }
    }

    public function testThePublicNameAloneHoldsEveryRoleToo(): void
    {
        self::assertSame([403, true], $this->signedInAs(Role::CLERK));
    }

    /**
     * A fresh account of that role in a company of its own, signed in by password with no second factor: what a company
     * page answers it, and whether its own account says a factor is required.
     *
     * @return array{int, bool}
     */
    private function signedInAs(string $role): array
    {
        $email = $role.'@example.test';
        $this->createUser($email, self::PASSWORD, $this->createCompany(ucfirst($role)), roleName: $role, authenticator: false);
        $this->client->getCookieJar()->clear();
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful($role.' signs in: the password opens the enrolment');

        $this->getJson('/api/me/companies');
        $status = $this->client->getResponse()->getStatusCode();
        if (Response::HTTP_FORBIDDEN === $status) {
            self::assertSame('mfa_enrolment_required', $this->stringAt($this->json(), 'error'), $role);
        }
        $this->getJson('/api/auth/me');

        return [$status, $this->boolAt($this->section($this->json(), 'mfa'), 'required')];
    }
}
