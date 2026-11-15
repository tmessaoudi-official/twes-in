<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Audit\Domain\Model\AuditLog;
use App\Identity\Application\Login\LoginAudit;
use App\Identity\Domain\Model\PasswordAddressLock;
use App\Identity\Domain\Model\User;

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
            if (self::SESSION_COOKIE === $candidate->getName()) {
                $cookie = $candidate;
            }
        }
        // The __Host- prefix makes the browser refuse the cookie unless it is Secure, for / and bound to this host alone:
        // a neighbouring subdomain can then neither set nor shadow it.
        self::assertNotNull($cookie, 'the session cookie is named __Host-twes_session');
        self::assertTrue($cookie->isSecure());
        self::assertSame('/', $cookie->getPath());
        self::assertStringNotContainsStringIgnoringCase('domain=', (string) $cookie, 'no Domain attribute: this host alone');
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

        $rows = $this->em()->getRepository(AuditLog::class)->findBy(['entityId' => $user->getId()]);
        // Both rows may share one second (audit_log.at is to the second), so the set, and the login found by its action.
        self::assertEqualsCanonicalizing([LoginAudit::LOGIN_FAILED, LoginAudit::LOGIN], array_map(static fn (AuditLog $r) => $r->getAction(), $rows));
        $login = array_values(array_filter($rows, static fn (AuditLog $r): bool => LoginAudit::LOGIN === $r->getAction()))[0];
        self::assertSame($user->getId()->toRfc4122(), $login->getActorUserId()?->toRfc4122());
        self::assertNotNull($login->getCompanyId(), 'the one membership becomes the session company, recorded on the row');
        self::assertSame('127.0.0.1', $login->getIp());
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
        self::assertResponseHeaderSame('content-type', 'application/json');
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

        // Both are journalled, by names only: the address typed is a person's, kept out as the journal keeps every one.
        // Read as a set, since two entries in one second tie on their moment.
        $failed = $this->em()->getRepository(AuditLog::class)->findBy(['action' => LoginAudit::LOGIN_FAILED]);
        self::assertCount(2, $failed);
        foreach ($failed as $entry) {
            self::assertArrayNotHasKey('email', $entry->getChanges());
            self::assertArrayNotHasKey('password', $entry->getChanges());
        }
        self::assertCount(1, array_filter($failed, static fn (AuditLog $entry): bool => null === $entry->getEntityId()), 'no such account, no entity to point at');
    }

    public function testAMalformedLoginBodyIsABadRequest(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'owner@example.test']);
        self::assertResponseStatusCodeSame(400);
    }

    public function testFiveFailuresLockThePasswordFromThatAddressEvenAgainstTheRightOne(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        for ($i = 0; $i < 5; ++$i) {
            $this->login('owner@example.test', 'wrong');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('owner@example.test', self::PASSWORD);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'invalid_credentials'], $this->json(), 'the lock is never told: telling it would say the address is real');
        $lock = $this->addressLock($user, '127.0.0.1');
        self::assertSame(5, $lock?->getFailedCount());
        self::assertNotNull($lock->getLockedUntil());
        $user = $this->reload($user);
        self::assertSame(5, $user->getFailedLoginCount(), 'the account counts them toward its own, far higher, ceiling');
        self::assertNull($user->getLockedUntil(), 'and is not locked everywhere by one address');
    }

    /** Whoever guesses from one address cannot keep the account's owner out from another. */
    public function testFiveWrongPasswordsFromOneAddressLeaveTheOwnerSigningInFromAnother(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->from('203.0.113.9');
        for ($i = 0; $i < 5; ++$i) {
            $this->login('owner@example.test', 'wrong');
        }
        $this->login('owner@example.test', self::PASSWORD);
        self::assertResponseStatusCodeSame(401, 'locked from where the guesses came');

        $this->from('198.51.100.7');
        $this->login('owner@example.test', self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    /** One machine on IPv6 picks the last 64 bits of its address at will: its network is the one address. */
    public function testAnIpv6NetworkIsOneAddress(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        foreach (['a', 'b', 'c', 'd', 'e'] as $host) {
            $this->from('2001:db8:1:2::'.$host);
            $this->login('owner@example.test', 'wrong');
        }

        $this->from('2001:db8:1:2::f');
        $this->login('owner@example.test', self::PASSWORD);
        self::assertResponseStatusCodeSame(401);

        $this->from('2001:db8:1:3::1');
        $this->login('owner@example.test', self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    /** Guesses spread over many addresses meet the account's ceiling, which locks the password everywhere. */
    public function testGuessesFromManyAddressesLockThePasswordEverywhere(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        for ($address = 1; $address <= 10; ++$address) {
            $this->from('198.51.100.'.$address);
            for ($i = 0; $i < 5; ++$i) {
                $this->login('owner@example.test', 'wrong');
            }
            self::assertSame([$address < 10 ? 'address_locked' : 'account_locked'], $this->securityNotices(), 'each address told once, the tenth locking it everywhere');
        }

        $this->from('192.0.2.200');
        $this->login('owner@example.test', self::PASSWORD);

        self::assertResponseStatusCodeSame(401);
        self::assertNotNull($this->reload($user)->getLockedUntil());
    }

    /** The right password from an address forgets that address's guesses, never another's. */
    public function testSigningInForgetsOnlyTheGuessesMadeFromThere(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->from('203.0.113.9');
        $this->login('owner@example.test', 'wrong');
        $this->from('198.51.100.7');
        $this->login('owner@example.test', 'wrong');
        $this->login('owner@example.test', self::PASSWORD);

        self::assertNull($this->addressLock($user, '198.51.100.7'));
        self::assertSame(1, $this->addressLock($user, '203.0.113.9')?->getFailedCount());
    }

    /**
     * While the lock holds, no password is compared: the right one and a wrong one answer as an address nobody registered
     * does, so the lock can no longer tell a guesser which guess was right, and neither counts toward the lock.
     */
    public function testWhileLockedTheRightAndTheWrongPasswordAnswerAlikeAndNeitherIsCompared(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        for ($i = 0; $i < 5; ++$i) {
            $this->login('owner@example.test', 'wrong');
        }

        $this->login('nobody@example.test', 'wrong');
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->json()];
        $this->login('owner@example.test', self::PASSWORD);
        $right = [$this->client->getResponse()->getStatusCode(), $this->json()];
        $this->login('owner@example.test', 'another guess');
        $wrong = [$this->client->getResponse()->getStatusCode(), $this->json()];

        self::assertSame([401, ['error' => 'invalid_credentials']], $unknown);
        self::assertSame($unknown, $right);
        self::assertSame($unknown, $wrong);
        self::assertSame(5, $this->reload($user)->getFailedLoginCount(), 'a guess made during the lock is not counted');
        // Refused by the lock before the password step: a wrong password compared would be recorded as one.
        $reasons = array_map(
            static fn (AuditLog $row): mixed => $row->getChanges()['reason'] ?? null,
            $this->em()->getRepository(AuditLog::class)->findBy(['action' => LoginAudit::LOGIN_FAILED, 'entityId' => $user->getId()]),
        );
        sort($reasons);
        self::assertSame(['BadCredentialsException', 'BadCredentialsException', 'BadCredentialsException', 'BadCredentialsException', 'BadCredentialsException', 'LockedAccountRefused', 'LockedAccountRefused'], $reasons);
    }

    /** The owner hears of the lock once, when it is set, never once per guess: a lock is no way to flood their mailbox. */
    public function testTheOwnerIsMailedOnceWhenTheLockIsSet(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        for ($i = 0; $i < 4; ++$i) {
            $this->login('owner@example.test', 'wrong');
            self::assertSame([], $this->securityNotices(), "no mail after guess $i");
        }

        $this->login('owner@example.test', 'wrong');
        self::assertSame(['address_locked'], $this->securityNotices(), 'the guess that locks its address tells its owner');

        $this->login('owner@example.test', 'wrong');
        self::assertSame([], $this->securityNotices());
        $this->login('owner@example.test', self::PASSWORD);
        self::assertSame([], $this->securityNotices());
    }

    public function testTenAttemptsFromOneAddressAreThrottledBeforeTheAccountIsEvenConsulted(): void
    {
        for ($i = 0; $i < 10; ++$i) {
            $this->login('ghost@example.test', 'wrong');
            self::assertResponseStatusCodeSame(401);
        }

        $this->login('ghost@example.test', 'wrong');

        self::assertResponseStatusCodeSame(429);
        // Symfony's login throttle says its wait in whole minutes (LoginThrottlingListener), which the answer turns into seconds.
        $this->assertTheWaitIsSaid(900, roundedTo: 60);
        self::assertSame(['error' => 'too_many_attempts'], $this->json());
    }

    public function testADeactivatedAccountCannotSignIn(): void
    {
        $this->createUser('gone@example.test', self::PASSWORD, active: false);
        $this->login('gone@example.test', self::PASSWORD);

        self::assertResponseStatusCodeSame(401);
        self::assertSame(['error' => 'account_disabled'], $this->json());
    }

    /**
     * A wrong password tells nothing about the account: a locked or a deactivated one answers it as an address nobody
     * registered does, so five guesses no longer say which addresses are real. Only the right password hears that an
     * account is deactivated; a locked one answers the right password as a wrong one too, and its owner hears by mail.
     */
    public function testALockedOrDeactivatedAccountAnswersAWrongPasswordAsAnUnknownAddressDoes(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->createUser('gone@example.test', self::PASSWORD, active: false);
        for ($i = 0; $i < 5; ++$i) {
            $this->login('owner@example.test', 'wrong');
        }

        $this->login('nobody@example.test', 'wrong');
        $unknown = [$this->client->getResponse()->getStatusCode(), $this->json()];
        $this->login('owner@example.test', 'wrong');
        $locked = [$this->client->getResponse()->getStatusCode(), $this->json()];
        $this->login('gone@example.test', 'wrong');
        $deactivated = [$this->client->getResponse()->getStatusCode(), $this->json()];

        self::assertSame([401, ['error' => 'invalid_credentials']], $unknown);
        self::assertSame($unknown, $locked);
        self::assertSame($unknown, $deactivated);
    }

    /** Guesses against an account already locked buy nothing, not even a longer lock for its owner. */
    public function testAWrongPasswordAgainstALockedAccountDoesNotExtendTheLock(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        for ($i = 0; $i < 5; ++$i) {
            $this->login('owner@example.test', 'wrong');
        }

        try {
            $this->moveClock('+14 minutes');
            $this->login('owner@example.test', 'wrong');
            self::assertResponseStatusCodeSame(401);

            $this->moveClock('+16 minutes');
            $this->login('owner@example.test', self::PASSWORD);
            self::assertResponseIsSuccessful();
        } finally {
            $this->clockBack();
        }
    }

    /** The account's state is told before any second factor is asked for, as it is to an account without one. */
    public function testADeactivatedAccountWithASecondFactorIsRefusedBeforeTheCodeIsAsked(): void
    {
        $this->createUser('gone@example.test', self::PASSWORD, active: false, authenticator: true);

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
        $user->rotateSecurityStamp(self::heldNow());
        $this->em()->flush();

        $this->client->request('GET', '/api/auth/me');
        self::assertResponseStatusCodeSame(401);
    }

    public function testDeactivatingASignedInUserEndsTheirSession(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);

        $user = $this->reload($user);
        $user->setActive(false, self::heldNow());
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

    /**
     * A hop on the private network may say the client's address and scheme, never the host or the port: nothing in front
     * of the API sets those, so a host it names is a forgery, and believing it lets a foreign page pass the origin check.
     */
    public function testAForwardedHostIsNotBelieved(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD);
        $this->client->setServerParameters([
            'REMOTE_ADDR' => '10.0.0.5',
            'HTTP_X_FORWARDED_HOST' => 'evil.example',
            'HTTP_ORIGIN' => 'http://evil.example',
            'HTTP_REFERER' => 'http://evil.example/',
        ]);

        $this->postJson('/api/auth/login', ['email' => 'owner@example.test', 'password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(['error' => 'csrf_token_invalid'], $this->json());
    }

    /** The address a private hop forwards is the one the journal keeps: the edge replaces whatever a client sent. */
    public function testTheForwardedClientAddressIsTheOneRecorded(): void
    {
        $user = $this->createUser('owner@example.test', self::PASSWORD);
        $this->client->setServerParameters(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9']);
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        $this->login('owner@example.test', self::PASSWORD);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['203.0.113.9'],
            $this->em()->getConnection()->fetchFirstColumn("SELECT ip FROM audit_log WHERE action = 'auth.login' AND entity_id = ?", [$user->getId()->toRfc4122()]),
        );
    }

    public function testTheSessionIdChangesAtLogin(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD);
        $this->client->request('GET', '/api/auth/me');
        $before = $this->client->getCookieJar()->get(self::SESSION_COOKIE)?->getValue();

        $this->login('owner@example.test', self::PASSWORD);
        $after = $this->client->getCookieJar()->get(self::SESSION_COOKIE)?->getValue();

        self::assertNotNull($after);
        self::assertNotSame($before, $after, 'session fixation: the id is rotated when the user signs in');
    }

    public function testTheUserRowIsNeverExposedBeyondTheMeShape(): void
    {
        $this->createUser('owner@example.test', self::PASSWORD, $this->createCompany());
        $this->login('owner@example.test', self::PASSWORD);

        self::assertSame(['user', 'company', 'permissions', 'mfa', 'modules', 'plannedModules', 'customerScreenCompanyId', 'support', 'supportAccessUntil', 'supportAccessReason', 'companyProof', 'phonesOutsideRanges', 'stepUpProof'], array_keys($this->json()));
        self::assertSame(['id', 'email', 'displayName', 'locale', 'isPlatformOperator', 'demo'], array_keys($this->section($this->json(), 'user')));
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
        self::assertCount(15, $planned);
        self::assertSame(['key' => 'composites', 'planned' => 'later'], $planned[0]);
        self::assertSame(['key' => 'zakat', 'planned' => 'later'], $planned[14]);
        self::assertNotContains('customers', array_column($planned, 'key'), 'a module that ships is never planned');
        self::assertNotContains('purchases', array_column($planned, 'key'), 'nor one that shipped since');
        self::assertNotContains('accounting_export', array_column($planned, 'key'), 'nor one that has since shipped');
        self::assertNotContains('recurring', array_column($planned, 'key'));
        self::assertNotContains('statements', array_column($planned, 'key'), 'nor one retired from the plan, its work built elsewhere');
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

    /** The requests that follow come from this address, the API's only hop being none. */
    private function from(string $address): void
    {
        $this->client->setServerParameter('REMOTE_ADDR', $address);
    }

    private function addressLock(User $user, string $address): ?PasswordAddressLock
    {
        $this->em()->clear();

        return $this->em()->getRepository(PasswordAddressLock::class)->findOneBy(['user' => $user->getId(), 'address' => $address]);
    }
}
