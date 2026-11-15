<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * A staff phone lost or a till left signed in: the company's owner sees where a member is signed in, on what and from
 * where, and signs them out there; the member is told by mail and the company's journal records it. Only the sessions
 * that entered this company are shown, never those another company of the same account was used in.
 */
final class MemberSessionsTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
        $this->company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', self::PASSWORD, $this->company, ['*'], Role::OWNER);
    }

    public function testTheOwnerEndsAStaffSessionAndItNoLongerWorks(): void
    {
        $clerk = $this->member('clerk@twes.local');
        $phone = $this->signedInOn('clerk@twes.local', 'Safari on iPhone');
        $this->ownerSignedIn();

        $this->getJson($this->path($clerk));
        self::assertResponseIsSuccessful();
        $sessions = $this->jsonList();
        self::assertSame(['Safari on iPhone'], array_map(fn (array $s): string => $this->stringAt($s, 'device'), $sessions));
        self::assertNotSame('', $this->stringAt($sessions[0], 'address'), 'the address it was used from is shown');

        $this->sendJson('DELETE', $this->path($clerk).'/'.$this->stringAt($sessions[0], 'id'));

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(['sessions_ended_by_owner'], $this->securityNotices(), 'the clerk is told who signed them out');
        self::assertSame(1, $this->rowCount(\sprintf(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'account.sessions_ended_by_owner' AND entity_id = '%s' AND company_id = '%s'",
            $clerk->getId()->toRfc4122(),
            $this->company->getId()->toRfc4122(),
        )));
        $this->getJson($this->path($clerk));
        self::assertSame([], $this->jsonList(), 'an ended session is no longer listed');
        $this->actAs($phone);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED, 'the lost phone is signed out');
    }

    public function testEndingThemAllSignsOutEveryDeviceTheCompanyWasUsedOn(): void
    {
        $clerk = $this->member('clerk@twes.local');
        $phone = $this->signedInOn('clerk@twes.local', 'Safari on iPhone');
        $till = $this->signedInOn('clerk@twes.local', 'Firefox on Linux');
        $this->ownerSignedIn();

        $this->sendJson('DELETE', $this->path($clerk));

        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['ended'] ?? null);
        self::assertSame(['sessions_ended_by_owner'], $this->securityNotices(), 'told once, however many ended');
        foreach ([$phone, $till] as $device) {
            $this->actAs($device);
            $this->client->request('GET', '/api/auth/me');
            self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        }
    }

    /**
     * A member of two companies proves themselves for each before acting there, so a session that never entered this one
     * cannot act in it without a proof: what it does elsewhere is the other company's, neither shown nor ended here.
     */
    public function testASessionUsedOnlyInAnotherCompanyIsNeitherShownNorEnded(): void
    {
        $globex = $this->createCompany('Globex');
        $clerk = $this->createUser('clerk@twes.local', self::PASSWORD, $globex, ['company.read'], Role::MEMBER);
        $elsewhere = $this->signedInOn('clerk@twes.local', 'Safari on iPhone');
        $this->join($clerk, $this->company);
        $this->actAs($elsewhere);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        $here = $this->signedInOn('clerk@twes.local', 'Firefox on Linux', $this->company);
        $this->ownerSignedIn();

        $this->getJson($this->path($clerk));
        self::assertSame(['Firefox on Linux'], array_map(fn (array $s): string => $this->stringAt($s, 'device'), $this->jsonList()));

        $this->sendJson('DELETE', $this->path($clerk));
        self::assertSame(1, $this->json()['ended'] ?? null);
        $this->actAs($elsewhere);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful('the session of the other company stays in');
        $this->actAs($here);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** The owner's alone, whatever a custom role grants, as lifting a lock is: said before any proof is asked. */
    public function testAnAdminNeitherSeesNorEndsAMembersSessions(): void
    {
        $this->createUser('admin@twes.local', self::PASSWORD, $this->company, ['user.read', 'user.write', 'company.read'], Role::ADMIN);
        $clerk = $this->member('clerk@twes.local');
        $phone = $this->signedInOn('clerk@twes.local', 'Safari on iPhone');
        $this->freshBrowser();
        $this->login('admin@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path($clerk));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('owner_only', $this->json()['error'] ?? null);
        $this->sendJson('DELETE', $this->path($clerk));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('owner_only', $this->json()['error'] ?? null, 'refused before the proof');

        $this->actAs($phone);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful('still signed in');
    }

    public function testAnOwnersOwnSessionsAreEndedFromTheirAccountNotHere(): void
    {
        $this->ownerSignedIn();
        $owner = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('owner@twes.local')]);
        self::assertInstanceOf(User::class, $owner);

        $this->getJson($this->path($owner));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('own_sessions', $this->json()['error'] ?? null);
        $this->sendJson('DELETE', $this->path($owner));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('own_sessions', $this->json()['error'] ?? null);
    }

    public function testSomeoneOfAnotherCompanyIsNoneOfTheOwnersBusiness(): void
    {
        $stranger = $this->createUser('stranger@twes.local', self::PASSWORD, $this->createCompany('Globex'));
        $theirs = $this->signedInOn('stranger@twes.local', 'Safari on iPhone');
        $this->ownerSignedIn();

        $this->getJson($this->path($stranger));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('member_not_found', $this->json()['error'] ?? null);
        $this->sendJson('DELETE', $this->path($stranger));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->securityNotices());

        $this->actAs($theirs);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    /** Reading where a member signs in asks no proof; signing them out, a change to how they sign in, does. */
    public function testEndingASessionWaitsForARecentProof(): void
    {
        $clerk = $this->member('clerk@twes.local');
        $phone = $this->signedInOn('clerk@twes.local', 'Safari on iPhone');
        $this->freshBrowser();
        $this->login('owner@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->getJson($this->path($clerk));
        self::assertResponseIsSuccessful();
        $this->sendJson('DELETE', $this->path($clerk));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->actAs($phone);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful('nothing ended');
    }

    public function testASessionNotAmongTheMembersIsNotFoundAndNothingIsTold(): void
    {
        $clerk = $this->member('clerk@twes.local');
        $this->signedInOn('clerk@twes.local', 'Safari on iPhone');
        $this->ownerSignedIn();

        $this->sendJson('DELETE', $this->path($clerk).'/018f0000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('session_not_found', $this->json()['error'] ?? null);
        self::assertSame([], $this->securityNotices());
        $this->getJson($this->path($clerk));
        self::assertCount(1, $this->jsonList());
    }

    private function member(string $email): User
    {
        $user = $this->createUser($email, self::PASSWORD);
        $this->join($user, $this->company);

        return $user;
    }

    private function join(User $user, Company $company): void
    {
        $role = $this->em()->getRepository(Role::class)->findOneBy(['name' => Role::MEMBER, 'company' => null]);
        self::assertInstanceOf(Role::class, $role);
        $found = $this->em()->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $found);
        $person = $this->em()->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $person);
        $this->em()->persist(new Membership($person, $found, $role, self::heldNow()));
        $this->em()->flush();
    }

    /**
     * A browser of its own, signed in and used once, which records its session with the company the sign-in opened;
     * for a member of two companies, the one named is proved as the screen asks.
     *
     * @return list<Cookie>
     */
    private function signedInOn(string $email, string $device, ?Company $proving = null): array
    {
        $this->freshBrowser($device);
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful();
        if (null !== $proving) {
            $this->proveCompany($proving, self::PASSWORD);
        }
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();
        // The companies a sign-in proved are noted from its next request on.
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseIsSuccessful();

        return array_values($this->client->getCookieJar()->all());
    }

    private function ownerSignedIn(): void
    {
        $this->freshBrowser('Edge on Windows');
        $this->login('owner@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        $this->stepUp(self::PASSWORD);
    }

    private function freshBrowser(string $device = 'Chrome on Android'): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->setServerParameter('HTTP_USER_AGENT', $device);
    }

    /** @param list<Cookie> $cookies */
    private function actAs(array $cookies): void
    {
        $this->client->getCookieJar()->clear();
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
    }

    private function path(User $member): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/members/'.$member->getId()->toRfc4122().'/sessions';
    }

    private function rowCount(string $sql): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsInt($count);

        return $count;
    }
}
