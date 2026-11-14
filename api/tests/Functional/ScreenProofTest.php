<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Identity\Application\Login\PasskeySignInSettings;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\ValueObject\Email;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tests\Support\FakeAuthenticator;
use Symfony\Component\HttpFoundation\Response;

/**
 * Proving who is at the screen is one canon for the step-up and a company's entry: each offers only the proofs the
 * account holds, and asks the authenticator code beside the password where a second factor is required of the account,
 * as its sign-in does. A proof is remembered with its strength, so one given without the code never passes where the
 * code is required.
 */
final class ScreenProofTest extends ApiTestCase
{
    private const string PASSWORD = 'a-long-enough-password';
    private const string EMAIL = 'clerk@twes.local';
    private const string STEP_UP = '/api/auth/step-up';

    private Company $acme;
    private Company $globex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->acme = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->acme);
        $this->globex = $this->createCompany('Globex');
    }

    public function testWhereASecondFactorIsRequiredTheStepUpAsksTheCodeBesideThePassword(): void
    {
        $this->requireSecondFactor($this->acme);
        $this->member(authenticator: true);

        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => false, 'code' => true, 'password' => true], $this->json()['stepUpProof']);

        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->json()['error'] ?? null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->export(), 'a password alone proves nothing here');

        // The sign-in spent this step's code: the next one proves.
        $this->moveClock('+31 seconds');
        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD, 'code' => self::authenticatorCode()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(Response::HTTP_OK, $this->export());
    }

    public function testTheCodeIsAskedWhereAnotherCompanyOfTheAccountRequiresASecondFactor(): void
    {
        $this->requireSecondFactor($this->globex);
        $this->member(authenticator: true, alsoIn: $this->globex);

        $this->getJson('/api/auth/me');
        self::assertSame('Acme', $this->section($this->json(), 'company')['name']);
        self::assertSame(['passkey' => false, 'code' => true, 'password' => true], $this->json()['stepUpProof']);
        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->json()['error'] ?? null);
    }

    public function testAProofGivenWithoutTheCodeDoesNotPassWhereTheCodeIsRequired(): void
    {
        $this->requireSecondFactor($this->globex);
        $this->member(authenticator: true, alsoIn: $this->globex);
        $initech = $this->createCompany('Initech');
        $this->addMembership($this->account(), $initech, Role::OWNER);

        // Entering a company that asks no code takes the password alone, and remembers that proof as it is.
        $this->postJson('/api/companies/'.$initech->getId()->toRfc4122().'/proof', ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->export());
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));
    }

    public function testAWrongCodeAtACompanysEntryLeavesNoProofBehind(): void
    {
        $this->requireSecondFactor($this->globex);
        $this->member(authenticator: true, alsoIn: $this->globex);
        $this->moveClock('+31 seconds');

        $this->postJson('/api/companies/'.$this->globex->getId()->toRfc4122().'/proof', ['password' => self::PASSWORD, 'code' => '000000' === self::authenticatorCode() ? '111111' : '000000']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->json()['error'] ?? null);

        self::assertSame(Response::HTTP_FORBIDDEN, $this->export(), 'the right password beside a wrong code proved nothing');
    }

    public function testThePasskeyIsOfferedOnlyToAnAccountHoldingOne(): void
    {
        $this->member();

        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => false, 'code' => false, 'password' => true], $this->json()['stepUpProof']);

        $this->registerPasskey();
        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => true, 'code' => false, 'password' => true], $this->json()['stepUpProof']);
    }

    public function testACompanyTakesAPasskeyToEnterItWhateverTheInstallationsPasskeySignIn(): void
    {
        $this->member(alsoIn: $this->globex);
        $authenticator = $this->registerPasskey();
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(), PasskeySignInSettings::ALLOWED, SettingLevel::Platform, false, null);
        $this->postJson('/api/me/company', ['companyId' => $this->globex->getId()->toRfc4122()]);
        self::assertResponseIsSuccessful();

        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => true, 'code' => false, 'password' => true], $this->json()['companyProof']);
        $proof = '/api/companies/'.$this->globex->getId()->toRfc4122().'/proof';
        $this->postJson($proof.'/passkey/options', null);
        self::assertResponseIsSuccessful();
        $this->postJson($proof.'/passkey', ['credential' => $authenticator->assert($this->json())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    public function testAnAccountWhoseSecondFactorIsAPasskeyStepsUpWithItAloneWhereOneIsRequired(): void
    {
        $this->member();
        $authenticator = $this->registerPasskey();
        $this->requireSecondFactor($this->acme);

        $this->getJson('/api/auth/me');
        self::assertSame(['passkey' => true, 'code' => false, 'password' => false], $this->json()['stepUpProof']);
        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('passkey_required', $this->json()['error'] ?? null);
        self::assertSame(Response::HTTP_FORBIDDEN, $this->export());

        $this->postJson(self::STEP_UP.'/passkey/options', null);
        self::assertResponseIsSuccessful();
        $this->postJson(self::STEP_UP.'/passkey', ['credential' => $authenticator->assert($this->json())]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertSame(Response::HTTP_OK, $this->export());
    }

    public function testAnOperatorsStepUpAsksTheCodeAsTheirSignInDoes(): void
    {
        $this->createUser('operator@twes.local', self::PASSWORD, operator: true);
        $this->login('operator@twes.local', self::PASSWORD);

        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('invalid_code', $this->json()['error'] ?? null);
    }

    private function member(bool $authenticator = false, ?Company $alsoIn = null): void
    {
        $user = $this->createUser(self::EMAIL, self::PASSWORD, $this->acme, authenticator: $authenticator);
        if (null !== $alsoIn) {
            $this->addMembership($user, $alsoIn, Role::OWNER);
        }
        $this->login(self::EMAIL, self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    private function requireSecondFactor(Company $company): void
    {
        $found = $this->em()->find(Company::class, $company->getId()) ?? throw new \LogicException('The company is gone.');
        $found->requireMfa(true, self::heldNow());
        $this->em()->flush();
    }

    private function registerPasskey(): FakeAuthenticator
    {
        $authenticator = new FakeAuthenticator();
        $this->postJson(self::STEP_UP, ['password' => self::PASSWORD]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->postJson('/api/auth/mfa/passkeys/options', null);
        $this->postJson('/api/auth/mfa/passkeys', ['name' => 'Laptop', 'credential' => $authenticator->register($this->json(), residentKey: true)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $authenticator;
    }

    private function account(): User
    {
        return $this->em()->getRepository(User::class)->findOneBy(['email' => Email::fromString(self::EMAIL)]) ?? throw new \LogicException('The account is gone.');
    }

    private function export(): int
    {
        $this->client->request('GET', '/api/companies/'.$this->acme->getId()->toRfc4122().'/exports/customers.csv');

        return $this->client->getResponse()->getStatusCode();
    }
}
