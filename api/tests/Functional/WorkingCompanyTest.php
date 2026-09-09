<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\Role;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/** The company switcher: what it offers, and what it refuses to switch to. */
final class WorkingCompanyTest extends ApiTestCase
{
    public function testTheSwitcherOffersEveryCompanyTheUserBelongsTo(): void
    {
        $first = $this->createCompany('Acme');
        $second = $this->createCompany('Globex');
        $this->memberOfBoth($first, $second);

        $this->getJson('/api/me/companies');

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertCount(2, $rows);
        self::assertSame(['Acme', 'Globex'], array_map(static fn ($r) => $r['name'], $rows));
        self::assertSame(Role::OWNER, $rows[0]['role']);
    }

    public function testAnotherUsersCompanyIsNotOffered(): void
    {
        $mine = $this->createCompany('Acme');
        $theirs = $this->createCompany('Globex');
        $this->createUser('other@twes.local', 'password-1234', $theirs, ['*']);
        $this->createUser('user@twes.local', 'password-1234', $mine, ['*']);
        $this->login('user@twes.local', 'password-1234');

        $this->getJson('/api/me/companies');

        $rows = $this->jsonList();
        self::assertCount(1, $rows);
        self::assertSame('Acme', $rows[0]['name']);
    }

    public function testSwitchingChangesTheWorkingCompany(): void
    {
        $first = $this->createCompany('Acme');
        $second = $this->createCompany('Globex');
        $this->memberOfBoth($first, $second);

        $this->postJson('/api/me/company', ['companyId' => $second->getId()->toRfc4122()]);

        self::assertResponseIsSuccessful();
        self::assertSame('Globex', $this->json()['name']);
    }

    public function testTheChoiceIsWhatTheNextRequestSees(): void
    {
        $first = $this->createCompany('Acme');
        $second = $this->createCompany('Globex');
        $this->memberOfBoth($first, $second);
        $this->postJson('/api/me/company', ['companyId' => $second->getId()->toRfc4122()]);
        self::assertResponseIsSuccessful();

        $this->getJson('/api/auth/me');

        self::assertResponseIsSuccessful();
        self::assertSame('Globex', $this->section($this->json(), 'company')['name']);
    }

    public function testACompanyTheUserIsNotInIsRefused(): void
    {
        $mine = $this->createCompany('Acme');
        $theirs = $this->createCompany('Globex');
        $this->createUser('user@twes.local', 'password-1234', $mine, ['*']);
        $this->login('user@twes.local', 'password-1234');

        $this->postJson('/api/me/company', ['companyId' => $theirs->getId()->toRfc4122()]);

        // Same 404 a company that does not exist gets: membership is not something to probe for.
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testARefusedSwitchLeavesTheWorkingCompanyAlone(): void
    {
        $mine = $this->createCompany('Acme');
        $theirs = $this->createCompany('Globex');
        $this->createUser('user@twes.local', 'password-1234', $mine, ['*']);
        $this->login('user@twes.local', 'password-1234');
        $this->postJson('/api/me/company', ['companyId' => $theirs->getId()->toRfc4122()]);

        $this->getJson('/api/auth/me');

        self::assertSame('Acme', $this->section($this->json(), 'company')['name']);
    }

    public function testACompanyThatDoesNotExistIsRefused(): void
    {
        $mine = $this->createCompany('Acme');
        $this->createUser('user@twes.local', 'password-1234', $mine, ['*']);
        $this->login('user@twes.local', 'password-1234');

        $this->postJson('/api/me/company', ['companyId' => Uuid::v7()->toRfc4122()]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnIdentifierThatIsNotAUuidIsRefused(): void
    {
        $mine = $this->createCompany('Acme');
        $this->createUser('user@twes.local', 'password-1234', $mine, ['*']);
        $this->login('user@twes.local', 'password-1234');

        $this->postJson('/api/me/company', ['companyId' => 'not-a-uuid']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAnAnonymousCallerSeesNoCompanies(): void
    {
        $this->getJson('/api/me/companies');

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAnAnonymousCallerCannotSwitch(): void
    {
        $company = $this->createCompany('Acme');

        $this->postJson('/api/me/company', ['companyId' => $company->getId()->toRfc4122()]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSwitchingNeedsItsCsrfHeader(): void
    {
        $first = $this->createCompany('Acme');
        $second = $this->createCompany('Globex');
        $this->memberOfBoth($first, $second);

        $this->postJson('/api/me/company', ['companyId' => $second->getId()->toRfc4122()], withCsrf: false);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function memberOfBoth(Company $first, Company $second): void
    {
        $user = $this->createUser('user@twes.local', 'password-1234', $first, ['*']);
        $em = $this->em();
        $role = new Role(Role::OWNER, ['*'], $second);
        $em->persist($role);
        $em->persist(new Membership($user, $second, $role));
        $em->flush();
        $this->login('user@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
