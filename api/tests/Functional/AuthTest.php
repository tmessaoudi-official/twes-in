<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\Domain\AuditLog;
use App\Identity\Application\Login\LoginAudit;
use App\Identity\Domain\User;

final class AuthTest extends ApiTestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    public function testLoginAnswersTheSignedInStateAndSetsAHardenedSessionCookie(): void
    {
        $company = $this->createCompany('Demo');
        $user = $this->createUser('owner@example.test', self::PASSWORD, $company);

        $this->login('owner@example.test', self::PASSWORD);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertSame($user->getId()->toRfc4122(), $this->section($body, 'user')['id']);
        self::assertSame('owner@example.test', $this->section($body, 'user')['email']);
        self::assertSame('Demo', $this->section($body, 'company')['name']);
        self::assertSame('owner', $this->section($body, 'company')['role']);
        self::assertSame(['*'], $body['permissions']);

        $cookie = null;
        foreach ($this->client->getResponse()->headers->getCookies() as $candidate) {
            if ('twes_session' === $candidate->getName()) {
                $cookie = $candidate;
            }
        }
        self::assertNotNull($cookie, 'the session cookie is named twes_session');
        self::assertTrue($cookie->isSecure());
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertSame(0, $cookie->getExpiresTime(), 'session-scoped: no persistent lifetime');
    }

    public function testLoginWritesAnAuditRowAndResetsTheFailureCounter(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', 'wrong');
        $this->login('owner@example.test', self::PASSWORD);

        $user = $this->reload($user);
        self::assertSame(0, $user->getFailedLoginCount());
        self::assertNotNull($user->getLastLoginAt());

        $rows = $this->em()->getRepository(AuditLog::class)->findBy(['entityId' => $user->getId()], ['at' => 'ASC']);
        self::assertSame([LoginAudit::LOGIN_FAILED, LoginAudit::LOGIN], array_map(static fn (AuditLog $r) => $r->getAction(), $rows));
        self::assertSame($user->getId()->toRfc4122(), $rows[1]->getActorUserId()?->toRfc4122());
        self::assertNotNull($rows[1]->getCompanyId(), 'the one membership becomes the session company, recorded on the row');
        self::assertSame('127.0.0.1', $rows[1]->getIp());
    }

    public function testMeAnswersTheSameShapeOnceSignedInAnd401Before(): void
    {
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'authentication_required'], $this->json());

        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);
        $afterLogin = $this->json();

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(200);
        self::assertResponseHeaderSame('content-type', 'application/json; charset=utf-8');
        self::assertSame($afterLogin, $this->json());
    }

    public function testAUserWithoutAnyMembershipSignsInWithNoCompanyAndNoPermissions(): void
    {
        $this->createUser('operator@example.test', self::PASSWORD, operator: true);
        $this->login('operator@example.test', self::PASSWORD);

        self::assertResponseStatusCodeSame(200);
        $body = $this->json();
        self::assertTrue($this->section($body, 'user')['isPlatformOperator']);
        self::assertNull($body['company']);
        self::assertSame([], $body['permissions']);
    }

    public function testWrongPasswordAndUnknownEmailAreTheSameAnswer(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD);

        $this->login('owner@example.test', 'wrong');
        self::assertResponseStatusCodeSame(401);
        $wrongPassword = $this->json();

        $this->login('nobody@example.test', 'wrong');
        self::assertResponseStatusCodeSame(401);
        self::assertSame($wrongPassword, $this->json());
        self::assertSame(['error' => 'invalid_credentials'], $wrongPassword);

        $failed = $this->em()->getRepository(AuditLog::class)->findBy(['action' => LoginAudit::LOGIN_FAILED], ['at' => 'ASC']);
        self::assertCount(2, $failed);
        self::assertSame('nobody@example.test', $failed[1]->getChanges()['email']);
        self::assertNull($failed[1]->getEntityId(), 'no such account, no entity to point at');
        self::assertArrayNotHasKey('password', $failed[1]->getChanges());
    }

    public function testAMalformedLoginBodyIsABadRequest(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'owner@example.test']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testFiveFailuresLockTheAccountEvenAgainstTheRightPassword(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        for ($i = 0; $i < 5; ++$i) {
            $this->login('owner@example.test', 'wrong');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('owner@example.test', self::PASSWORD);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'account_locked'], $this->json());
        $user = $this->reload($user);
        self::assertSame(5, $user->getFailedLoginCount());
        self::assertNotNull($user->getLockedUntil());
    }

    public function testTenAttemptsFromOneAddressAreThrottledBeforeTheAccountIsEvenConsulted(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->login('ghost@example.test', 'wrong');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('ghost@example.test', 'wrong');

        self::assertResponseStatusCodeSame(429);
        self::assertSame(['error' => 'too_many_attempts'], $this->json());
    }

    public function testADeactivatedAccountCannotSignIn(): void
    {
        $this->createUser('gone@example.test', self::PASSWORD, active: false);
        $this->login('gone@example.test', self::PASSWORD);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'account_disabled'], $this->json());
    }

    public function testLogoutEndsTheSessionAndWritesAnAuditRow(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);

        $this->postJson('/api/auth/logout', null);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);

        $rows = $this->em()->getRepository(AuditLog::class)->findBy(['entityId' => $user->getId(), 'action' => LoginAudit::LOGOUT]);
        self::assertCount(1, $rows);
    }

    public function testARotatedSecurityStampForcesEverySessionOut(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(200);

        $user = $this->reload($user);
        $user->rotateSecurityStamp();
        $this->em()->flush();

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeactivatingASignedInUserEndsTheirSession(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);

        $user = $this->reload($user);
        $user->setActive(false);
        $this->em()->flush();

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testAProofGivenAtLoginMustNotDisappearLater(): void
    {
        // The framework remembers in the session that this client proved its origin; a later unsafe request
        // without any origin information is refused even with the header (SameOriginCsrfTokenManager).
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);
        self::assertResponseStatusCodeSame(200);

        // No Origin, no Sec-Fetch-Site, and no Referer either (BrowserKit derives one from its history).
        $this->client->setServerParameters([]);
        $this->client->getHistory()->clear();
        $this->postJson('/api/auth/logout', null);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'csrf_token_invalid'], $this->json());
    }

    public function testUnsafeRequestsWithoutTheCsrfHeaderAreRefusedBeforeAnythingElse(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD);

        $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD], withCsrf: false);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'csrf_token_missing'], $this->json());

        // A cross-site browser request: foreign Origin and Referer, and the fetch metadata says so.
        $this->client->setServerParameters(['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/', 'HTTP_SEC_FETCH_SITE' => 'cross-site']);
        $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'csrf_token_invalid'], $this->json());

        self::assertCount(0, $this->em()->getRepository(AuditLog::class)->findAll(), 'a refused request never reaches the firewall');
    }

    public function testTheSessionIdChangesAtLogin(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD);
        $this->client->request('GET', '/api/auth/me');
        $before = $this->client->getCookieJar()->get('twes_session')?->getValue();

        $this->login('owner@example.test', self::PASSWORD);
        $after = $this->client->getCookieJar()->get('twes_session')?->getValue();

        self::assertNotNull($after);
        self::assertNotSame($before, $after, 'session fixation: the id is rotated when the user signs in');
    }

    public function testTheUserRowIsNeverExposedBeyondTheMeShape(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);

        self::assertSame(['user', 'company', 'permissions', 'mfa', 'modules', 'plannedModules'], array_keys($this->json()));
        self::assertSame(['id', 'email', 'displayName', 'locale', 'isPlatformOperator'], array_keys($this->section($this->json(), 'user')));
        self::assertStringNotContainsString('argon', (string) $this->client->getResponse()->getContent());
    }

    // docs/SPEC.md § 7, 2026-09-26 10:08 (row 150): the menu shows the modules not built yet from the API's catalogue,
    // so the signed-in state names them, the same in the login answer and in /auth/me.
    public function testTheSignedInStateNamesThePlannedModulesTheMenuShows(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);
        $atLogin = $this->arrayAt($this->json(), 'plannedModules');

        $this->getJson('/api/auth/me');

        self::assertResponseIsSuccessful();
        $planned = $this->arrayAt($this->json(), 'plannedModules');
        self::assertSame($atLogin, $planned);
        self::assertCount(23, $planned);
        self::assertSame(['key' => 'accounting_export', 'planned' => 'v1'], $planned[0]);
        self::assertSame(['key' => 'zakat', 'planned' => 'later'], $planned[22]);
        self::assertNotContains('customers', array_column($planned, 'key'), 'a module that ships is never planned');
    }

    public function testAnAnonymousRequestStartsNoSession(): void
    {
        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);

        $names = array_map(static fn ($c) => $c->getName(), $this->client->getResponse()->headers->getCookies());
        self::assertSame([], $names, 'a 401 must not leave a session row behind for every anonymous hit, and the CSRF proof is a header, not a cookie');
    }

    public function testEntitiesBackingTheEndpointsMapToTheDatabase(): void
    {
        $count = $this->em()->getRepository(User::class)->count([]);
        self::assertSame(0, $count, 'the transaction starts empty: dama rolls every test back');
    }
}
