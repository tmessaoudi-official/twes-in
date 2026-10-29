<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Operators end an account's sessions, deactivate it and reactivate it, audited, from the platform (docs/SPEC.md
 * § 7, 2026-09-15). Nobody else may: a company's owner removes someone from the company instead.
 */
final class PlatformAccountsTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    public function testAnOperatorFindsAccountsByPartOfTheirAddressOrName(): void
    {
        $this->createUser('nadia@acme.test', self::PASSWORD);
        $this->createUser('sami@other.test', self::PASSWORD);
        $this->signedInAsOperator();

        $this->getJson('/api/platform/accounts?q=acme');

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertSame(['nadia@acme.test'], array_column($rows, 'email'));
        self::assertTrue($rows[0]['active']);
        self::assertFalse($rows[0]['platformOperator']);
        self::assertIsString($rows[0]['id']);
        self::assertIsString($rows[0]['displayName']);
    }

    public function testTheListIsAPageWithItsTotalFilteredAndSortedAndEachAccountNamesItsCompanies(): void
    {
        $acme = $this->createCompany('Acme');
        $globex = $this->createCompany('Globex');
        $zoe = $this->createUser('zoe@acme.test', self::PASSWORD, $acme);
        $this->addMembership($zoe, $globex, 'member');
        $this->createUser('amel@acme.test', self::PASSWORD, $acme, roleName: 'member');
        $this->createUser('lost@nowhere.test', self::PASSWORD, active: false);
        $this->signedInAsOperator();

        $this->getJson('/api/platform/accounts?itemsPerPage=2&page=1');
        self::assertResponseIsSuccessful();
        self::assertSame(['amel@acme.test', 'lost@nowhere.test'], array_column($this->jsonList(), 'email'));
        self::assertSame(4, $this->jsonPage()['totalItems'], 'the operator is one of the four');

        $this->getJson('/api/platform/accounts?q=zoe');
        $companies = $this->jsonList()[0]['companies'];
        self::assertIsArray($companies);
        $rows = [];
        foreach ($companies as $company) {
            self::assertIsArray($company);
            $rows[] = [$company['name'], $company['role']];
        }
        self::assertSame([['Acme', 'owner'], ['Globex', 'member']], $rows, 'by company name, with the role held in each');

        $this->getJson('/api/platform/accounts?q=lost');
        self::assertSame([], $this->jsonList()[0]['companies'], 'an account of no company says so');

        $this->getJson('/api/platform/accounts?active=false');
        self::assertSame(['lost@nowhere.test'], array_column($this->jsonList(), 'email'));
        $this->getJson('/api/platform/accounts?platformOperator=true');
        self::assertSame(['op@twes.local'], array_column($this->jsonList(), 'email'));
        $this->getJson('/api/platform/accounts?order[email]=desc&itemsPerPage=1');
        self::assertSame(['zoe@acme.test'], array_column($this->jsonList(), 'email'));
        $this->getJson('/api/platform/accounts?order[nope]=1&active=maybe');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testAPageOfAccountsCostsTheSameNumberOfStatementsWhateverItsSize(): void
    {
        foreach (range(1, 10) as $n) {
            $this->createUser(\sprintf('user%02d@acme.test', $n), self::PASSWORD, $this->createCompany(\sprintf('Acme %02d', $n)));
        }
        $this->signedInAsOperator();

        self::assertSame($this->statementsForAPageOf('/api/platform/accounts', 3), $this->statementsForAPageOf('/api/platform/accounts', 10), 'companies are read once for the page, not once per account');
    }

    public function testNobodyButAnOperatorReachesAccounts(): void
    {
        $this->createUser('owner@acme.test', self::PASSWORD, $this->createCompany('Acme'));
        $target = $this->createUser('nadia@acme.test', self::PASSWORD);
        $this->login('owner@acme.test', self::PASSWORD);

        $this->getJson('/api/platform/accounts');
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->postJson($this->pathOf($target, 'deactivate'), []);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame([], $this->auditedActions($target));
    }

    public function testEndingSessionsSignsTheAccountOutEverywhere(): void
    {
        $target = $this->createUser('nadia@acme.test', self::PASSWORD);
        $theirs = $this->signedInElsewhere('nadia@acme.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($target, 'end-sessions'), []);

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['active']);
        self::assertSame(['operator_ended_sessions'], $this->securityNotices(), 'the person is told who signed them out');
        $this->resume($theirs);
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        self::assertSame(['account.sessions_ended'], $this->auditedActions($target));
    }

    public function testADeactivatedAccountIsSignedOutAndCannotSignInUntilReactivated(): void
    {
        $target = $this->createUser('nadia@acme.test', self::PASSWORD);
        $theirs = $this->signedInElsewhere('nadia@acme.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($target, 'deactivate'), []);
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['active']);
        // A deactivated account is still told: the mail goes to its address, which it cannot sign in to change.
        self::assertSame(['operator_deactivated'], $this->securityNotices());

        $this->resume($theirs);
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
        $this->client->getCookieJar()->clear();
        $this->login('nadia@acme.test', self::PASSWORD);
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->signedInAsOperator();
        $this->postJson($this->pathOf($target, 'reactivate'), []);
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['active']);
        self::assertSame(['operator_reactivated'], $this->securityNotices());

        $this->client->getCookieJar()->clear();
        $this->login('nadia@acme.test', self::PASSWORD);
        self::assertResponseIsSuccessful();
        self::assertSame(['account.deactivated', 'account.reactivated'], $this->auditedActions($target));
    }

    /**
     * A company's owner has nobody above them in it to lift the lock wrong passwords set on theirs: the operator does,
     * from the platform, audited, and the owner is told by mail (row 241). Wrong guesses are forgotten with it.
     */
    public function testAnOperatorLiftsTheLockOfAnOwnersPassword(): void
    {
        $target = $this->createUser('nadia@acme.test', self::PASSWORD, $this->createCompany('Acme'));
        $this->lockByWrongPasswords('nadia@acme.test');
        $this->signedInAsOperator();

        $this->getJson('/api/platform/accounts?q=nadia');
        self::assertIsString($this->jsonList()[0]['passwordLockedUntil'], 'the list says the lock and until when');

        $this->postJson($this->pathOf($target, 'unlock'), []);

        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['passwordLockedUntil']);
        self::assertContains('lock_lifted_by_operator', $this->securityNotices(), 'the owner is told who let them back in');
        self::assertSame(['account.lock_lifted'], $this->auditedActions($target));
        $this->client->getCookieJar()->clear();
        $this->login('nadia@acme.test', self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    /** Anyone else's lock is lifted by an owner of their company, or by the mailed link, never by the platform. */
    public function testAnOperatorDoesNotLiftTheLockOfAnAccountOwningNoCompany(): void
    {
        $target = $this->createUser('sami@acme.test', self::PASSWORD, $this->createCompany('Acme'), roleName: 'member');
        $this->lockByWrongPasswords('sami@acme.test');
        $this->signedInAsOperator();

        $this->postJson($this->pathOf($target, 'unlock'), []);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame([['propertyPath' => 'account', 'reason' => 'not_an_owner', 'params' => []]], $this->refusals());
        self::assertSame([], $this->auditedActions($target));
    }

    public function testAnOperatorCannotDeactivateTheirOwnAccount(): void
    {
        $operator = $this->signedInAsOperator();

        $this->postJson($this->pathOf($operator, 'deactivate'), []);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
    }

    public function testAnUnknownOrMalformedAccountIsNotFound(): void
    {
        $this->signedInAsOperator();

        $this->postJson('/api/platform/accounts/'.Uuid::v7()->toRfc4122().'/deactivate', []);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson('/api/platform/accounts/not-a-uuid/deactivate', []);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function signedInAsOperator(): User
    {
        $this->client->getCookieJar()->clear();
        $operator = $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString('op@twes.local')])
            ?? $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        self::assertResponseIsSuccessful();

        return $operator;
    }

    /** Five wrong passwords, which lock the password for the configured while. */
    private function lockByWrongPasswords(string $email): void
    {
        $this->client->getCookieJar()->clear();
        for ($i = 0; $i < 5; ++$i) {
            $this->login($email, 'not-the-password');
        }
        $this->client->getCookieJar()->clear();
    }

    /** @return list<Cookie> the session of that account, set aside while somebody else signs in */
    private function signedInElsewhere(string $email): array
    {
        $this->login($email, self::PASSWORD);
        self::assertResponseIsSuccessful();
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        $cookies = array_values($this->client->getCookieJar()->all());
        $this->client->getCookieJar()->clear();

        return $cookies;
    }

    /** @param list<Cookie> $cookies */
    private function resume(array $cookies): void
    {
        $this->client->getCookieJar()->clear();
        foreach ($cookies as $cookie) {
            $this->client->getCookieJar()->set($cookie);
        }
    }

    /** @return list<mixed> */
    private function auditedActions(User $user): array
    {
        return $this->em()->getConnection()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE entity_id = ? AND action LIKE 'account.%' ORDER BY at, action",
            [$user->getId()->toRfc4122()],
        );
    }

    private function pathOf(User $user, string $action): string
    {
        return '/api/platform/accounts/'.$user->getId()->toRfc4122().'/'.$action;
    }
}
