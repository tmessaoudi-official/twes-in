<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Application\CompanyProof\CompanyEntrySettings;
use App\Identity\Domain\Model\User;
use App\Inbox\Domain\Model\InboxItem;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Infrastructure\Realtime\HmacJwt;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tests\Support\FakeAuthenticator;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Entering a company asks, once per sign-in, the proof that company accepts, for an account with two companies or more:
 * the company the sign-in opens is proved by it, another asks when it is entered, and the proof lasts until sign-out,
 * thirty minutes idle or twelve hours. What the bell and the live changes reach follows it.
 */
final class CompanyProofTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string EMAIL = 'two@twes.local';

    private Company $first;
    private Company $second;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->first = $this->createCompany('Acme');
        $this->second = $this->createCompany('Globex');
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
        parent::tearDown();
    }

    public function testTheCompanyTheSignInOpensIsProvedAndAnotherAsksItsOwnProof(): void
    {
        $this->memberOfBoth();

        $this->getJson('/api/auth/me');
        self::assertSame('Acme', $this->section($this->json(), 'company')['name']);
        self::assertNull($this->json()['companyProof']);
        self::assertSame(Response::HTTP_OK, $this->act($this->first));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->second));
        self::assertStringContainsString('company_proof_required', $this->stringAt($this->json(), 'detail'));

        // Entering it is allowed: the session then says what proof it asks.
        $this->enter($this->second);
        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => false, 'code' => false, 'password' => true], $this->json()['companyProof']);

        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(Response::HTTP_OK, $this->act($this->second));
        $this->getJson('/api/auth/me');
        self::assertNull($this->json()['companyProof']);
    }

    public function testAWrongPasswordProvesNothing(): void
    {
        $this->memberOfBoth();

        $this->postJson($this->proof($this->second), ['password' => 'not-the-password']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_credentials', $this->json()['error'] ?? null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->second));
    }

    /**
     * The proof shares its budget with every other answer to who is at the screen: a right answer must give back what
     * it spent, or a member of several companies entering them one after the other has no attempt left for one typo.
     */
    public function testRightProofsNeverLeaveTheMemberShortOfAttempts(): void
    {
        $this->memberOfBoth();

        for ($proof = 0; $proof < 5; ++$proof) {
            $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
            self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        }
        $this->postJson($this->proof($this->second), ['password' => 'not-the-password']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the typo is answered, not held back');
        self::assertSame('invalid_credentials', $this->json()['error'] ?? null);
        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testACompanyOfSomeoneElseIsStillNoSuchCompany(): void
    {
        $this->memberOfBoth();
        $theirs = $this->createCompany('Initech');
        $this->createUser('other@twes.local', self::PASSWORD, $theirs);

        self::assertSame(Response::HTTP_NOT_FOUND, $this->act($theirs));
        $this->postJson($this->proof($theirs), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAccountWithOneCompanyIsNeverAsked(): void
    {
        $this->createUser(self::EMAIL, self::PASSWORD, $this->first);
        $this->login(self::EMAIL, self::PASSWORD);

        $this->getJson('/api/auth/me');
        self::assertNull($this->json()['companyProof']);
        // Nothing proved is held for it either: left alone longer than a proof lasts idle, it is still let in.
        $at = new \DateTimeImmutable();
        foreach ([29, 58] as $minutes) {
            Clock::set(new MockClock($at->modify("+$minutes minutes")));
            self::assertSame(Response::HTTP_OK, $this->act($this->first));
        }
    }

    public function testAMemberWhoseSecondFactorIsAPasskeyEntersACompanyRequiringOneWithItNotThePasswordAlone(): void
    {
        $this->memberOfBoth();
        $authenticator = new FakeAuthenticator();
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->json(), residentKey: true)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $second = $this->fresh($this->second);
        $second->requireMfa(true);
        $this->em()->flush();
        // A company that takes no passkey to enter still takes it as the second factor it requires.
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($second), CompanyEntrySettings::PASSKEY, SettingLevel::Company, false, null);
        $this->enter($this->second);

        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => true, 'code' => false, 'password' => false], $this->json()['companyProof']);
        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('passkey_required', $this->json()['error'] ?? null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->second));

        $this->postJson($this->proof($this->second).'/passkey/options', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->proof($this->second).'/passkey', ['credential' => $authenticator->assert($this->json())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(Response::HTTP_OK, $this->act($this->second));
    }

    public function testACompanyRequiringASecondFactorAsksTheCodeBesideThePassword(): void
    {
        $this->second->requireMfa(true);
        $this->em()->flush();
        $this->memberOfBoth(authenticator: true);
        $this->enter($this->second);
        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => false, 'code' => true, 'password' => true], $this->json()['companyProof']);

        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->json()['error'] ?? null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->second));

        // The sign-in spent this step's code: the next one proves.
        Clock::set(new MockClock(new \DateTimeImmutable('+31 seconds')));
        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD, 'code' => $this->code(new \DateTimeImmutable('+31 seconds'))]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(Response::HTTP_OK, $this->act($this->second));
    }

    public function testAProofLeftAloneThirtyMinutesIsAskedAgainWhileTheSignInGoesOn(): void
    {
        $this->memberOfBoth();
        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        // Used every twenty-nine minutes, it holds.
        $at = new \DateTimeImmutable();
        foreach ([29, 58, 87] as $minutes) {
            Clock::set(new MockClock($at->modify("+$minutes minutes")));
            self::assertSame(Response::HTTP_OK, $this->act($this->second), "after $minutes minutes");
            self::assertSame(Response::HTTP_OK, $this->act($this->first), "after $minutes minutes");
        }
        // Working in the other company keeps the sign-in alive, not this company's proof.
        Clock::set(new MockClock($at->modify('+107 minutes')));
        self::assertSame(Response::HTTP_OK, $this->act($this->first));
        Clock::set(new MockClock($at->modify('+118 minutes')));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->second));
    }

    public function testSigningOutForgetsIt(): void
    {
        $this->memberOfBoth();
        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->postJson('/api/auth/logout', null);
        $this->login(self::EMAIL, self::PASSWORD);

        self::assertSame(Response::HTTP_OK, $this->act($this->first));
        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->second));
    }

    public function testSigningInAgainOverTheSameSessionKeepsNoProof(): void
    {
        $this->memberOfBoth();
        $this->proveCompany($this->second, self::PASSWORD);

        $this->login(self::EMAIL, self::PASSWORD);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->second));
    }

    public function testTheLiveChangesAndTheBellFollowTheProof(): void
    {
        $this->memberOfBoth();
        $this->bell($this->first);
        $waiting = $this->bell($this->second);
        $this->enter($this->second);

        self::assertSame(['user:'.$this->user->getId()->toRfc4122()], $this->channels());
        $this->getJson('/api/me/notifications');
        self::assertSame([$this->first->getId()->toRfc4122()], array_column($this->arrayAt($this->json(), 'items'), 'companyId'));
        $this->getJson('/api/me/notifications/unread-by-company');
        self::assertSame([$this->first->getId()->toRfc4122()], array_keys($this->arrayAt($this->json(), 'counts')));
        // Nor is what waits read from here: marked one by one or all at once, it stays unread until the proof.
        $this->postJson("/api/me/notifications/$waiting/read", null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson('/api/me/notifications/read-all', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->postJson($this->proof($this->second), ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        self::assertSame(['user:'.$this->user->getId()->toRfc4122(), 'company:'.$this->second->getId()->toRfc4122()], $this->channels());
        $this->getJson('/api/me/notifications');
        self::assertCount(2, $this->arrayAt($this->json(), 'items'));
        self::assertSame(1, $this->json()['unread'], 'the first company\'s was read, the second\'s waited');
    }

    public function testAPasskeyProvesACompanyThatAcceptsOneAndNotOneThatRefusesIt(): void
    {
        $this->memberOfBoth(authenticator: true);
        $authenticator = new FakeAuthenticator();
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->json(), residentKey: true)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->enter($this->second);
        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => true, 'code' => false, 'password' => true], $this->json()['companyProof']);

        $this->postJson($this->proof($this->second).'/passkey/options', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->proof($this->second).'/passkey', ['credential' => $authenticator->assert($this->json())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(Response::HTTP_OK, $this->act($this->second));

        // A company that does not accept one asks the password, whatever the passkey.
        $third = $this->createCompany('Initech');
        $this->addMembership($this->fresh($this->user), $third, Role::OWNER);
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($third), CompanyEntrySettings::PASSKEY, SettingLevel::Company, false, null);
        $this->enter($third);
        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => false, 'code' => false, 'password' => true], $this->json()['companyProof']);
        $this->postJson($this->proof($third).'/passkey/options', null);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('passkey_sign_in_off', $this->json()['error'] ?? null);
    }

    public function testAPasskeyAloneSignInLeavesACompanyThatRefusesPasskeysToAskItsProof(): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($this->first), CompanyEntrySettings::PASSKEY, SettingLevel::Company, false, null);
        $this->memberOfBoth(authenticator: true);
        $authenticator = new FakeAuthenticator();
        $this->stepUp(self::PASSWORD);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->json(), residentKey: true)]);
        $this->sendJson('PUT', '/api/auth/mfa/passkey-sign-in', ['enabled' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->client->getCookieJar()->clear();

        $this->postJson('/api/auth/passkey-sign-in/options', null);
        $this->postJson('/api/auth/passkey-sign-in', ['credential' => $authenticator->assert($this->json(), withUserHandle: true)]);
        self::assertResponseIsSuccessful();

        // Acme opened, refusing a passkey: its proof is asked at once.
        self::assertSame(Response::HTTP_FORBIDDEN, $this->act($this->first));
        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => false, 'code' => false, 'password' => true], $this->json()['companyProof']);
    }

    private function memberOfBoth(bool $authenticator = false): void
    {
        $this->user = $this->createUser(self::EMAIL, self::PASSWORD, $this->first, authenticator: $authenticator);
        $this->addMembership($this->user, $this->second, Role::OWNER);
        $this->login(self::EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    private function act(Company $company): int
    {
        $this->getJson('/api/companies/'.$company->getId()->toRfc4122().'/security');

        return $this->client->getResponse()->getStatusCode();
    }

    private function enter(Company $company): void
    {
        $this->postJson('/api/me/company', ['companyId' => $company->getId()->toRfc4122()]);
        self::assertResponseIsSuccessful();
    }

    private function proof(Company $company): string
    {
        return '/api/companies/'.$company->getId()->toRfc4122().'/proof';
    }

    private function code(\DateTimeImmutable $at): string
    {
        return (new \App\Identity\Infrastructure\Mfa\OtphpTotpCodes())->codeAt(self::AUTHENTICATOR_SECRET, $at);
    }

    private function bell(Company $company): string
    {
        $item = new InboxItem($this->fresh($this->user), $this->fresh($company), 'invitation.accepted', [], new \DateTimeImmutable());
        $this->em()->persist($item);
        $this->em()->flush();

        return $item->getId()->toRfc4122();
    }

    /**
     * The test client reboots the kernel between requests, so an entity read before one is found again after it.
     *
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function fresh(object $entity): object
    {
        $id = $this->em()->getClassMetadata($entity::class)->getIdentifierValues($entity);

        return $this->em()->find($entity::class, $id) ?? throw new \LogicException('The entity is gone.');
    }

    /** @return list<mixed> */
    private function channels(): array
    {
        $this->getJson('/api/me/realtime-token');
        self::assertResponseIsSuccessful();
        [, $payload] = explode('.', $this->stringAt($this->json(), 'token'));
        $claims = json_decode(HmacJwt::base64UrlDecode($payload), true, 8, \JSON_THROW_ON_ERROR);
        self::assertIsArray($claims);
        self::assertIsList($claims['channels']);

        return $claims['channels'];
    }
}
