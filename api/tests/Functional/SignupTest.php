<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Settings\Application\ChangeSettings;
use App\Settings\Application\PlatformSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email as MimeEmail;

/**
 * Public signup (docs/SPEC.md § 3 Auth, onboarding): closed until an operator opens it, an address first, a mailed link,
 * and the answer never says whether the address already has an account. Every request here is logged out.
 */
final class SignupTest extends ApiTestCase
{
    private const string ADDRESS = 'new@twes.local';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBuiltInRoles();
    }

    public function testSignupIsClosedUntilAnOperatorOpensIt(): void
    {
        $this->getJson('/api/signup');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['enabled']);

        $this->postJson('/api/signup', ['email' => self::ADDRESS]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertEmailCount(0);
    }

    public function testAnOpenSignupSaysSoAndNamesTheCountriesACompanyMayBeIn(): void
    {
        $this->openSignup();

        $this->getJson('/api/signup');

        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['enabled']);
        self::assertSame(['FR', 'TN'], $this->json()['countries']);
    }

    public function testAnAddressIsMailedALinkThatDescribesIt(): void
    {
        $this->openSignup();

        $this->postJson('/api/signup', ['email' => self::ADDRESS, 'locale' => 'en']);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        $token = self::tokenIn($this->lastMailTo(self::ADDRESS));

        $this->getJson('/api/signup/'.$token);
        self::assertResponseIsSuccessful();
        self::assertSame(self::ADDRESS, $this->json()['email']);
    }

    public function testAnAddressWithAnAccountGetsTheSameAnswerAndNoLink(): void
    {
        $this->openSignup();
        $this->postJson('/api/signup', ['email' => self::ADDRESS]);
        $fresh = [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()];

        $this->createUser('taken@twes.local', 'password-1234');
        $this->postJson('/api/signup', ['email' => 'Taken@twes.local']);

        self::assertSame($fresh, [$this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent()]);
        $html = $this->lastMailTo('taken@twes.local');
        self::assertDoesNotMatchRegularExpression('#/signup/[0-9a-f]{64}#', $html, 'an account is never offered a second one');
    }

    public function testAMalformedAddressIsUnprocessable(): void
    {
        $this->openSignup();

        $this->postJson('/api/signup', ['email' => 'not an address']);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertEmailCount(0);
    }

    public function testFinishingMakesTheAccountAndACompanyWaitingForApproval(): void
    {
        $this->openSignup();
        $token = $this->requestALink();

        $this->postJson('/api/signup/'.$token.'/complete', $this->finish());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(Company::STATUS_PENDING, $this->json()['companyStatus']);
        self::assertSame(['signup.completed'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE action LIKE 'signup.%'"));

        // Finishing starts no session; the new owner signs in, and the company waits.
        $this->login(self::ADDRESS, 'a-long-enough-password');
        self::assertResponseIsSuccessful();
        $this->getJson('/api/auth/me');
        $company = $this->section($this->json(), 'company');
        self::assertSame('Nouvelle Société', $company['name']);
        self::assertSame(Company::STATUS_PENDING, $company['status']);
        self::assertSame('owner', $company['role']);
        self::assertSame('TND', $company['currency']);
        self::assertSame('Africa/Tunis', $company['timezone']);
        $this->getJson('/api/companies/'.$this->stringAt($company, 'id').'/profile');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testWithoutApprovalTheCompanyIsActiveAtOnce(): void
    {
        $this->openSignup();
        $this->setPlatform(PlatformSettings::SIGNUP_APPROVAL_REQUIRED, false);
        $token = $this->requestALink();

        $this->postJson('/api/signup/'.$token.'/complete', $this->finish());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(Company::STATUS_ACTIVE, $this->json()['companyStatus']);
        $this->login(self::ADDRESS, 'a-long-enough-password');
        $this->getJson('/api/auth/me');
        $this->getJson('/api/companies/'.$this->stringAt($this->section($this->json(), 'company'), 'id').'/profile');
        self::assertResponseIsSuccessful();
    }

    public function testALinkIsUsedOnce(): void
    {
        $this->openSignup();
        $token = $this->requestALink();
        $this->postJson('/api/signup/'.$token.'/complete', $this->finish());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->postJson('/api/signup/'.$token.'/complete', $this->finish(['companyName' => 'Another']));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/signup/'.$token);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnUnknownOrMalformedLinkIsNotFound(): void
    {
        $this->openSignup();

        $this->getJson('/api/signup/'.str_repeat('a', 64));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson('/api/signup/not-a-token/complete', $this->finish());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function refusedFinishes(): iterable
    {
        yield 'a country with no fiscal preset' => [['countryCode' => 'ZZ']];
        yield 'a zone that does not exist' => [['timezone' => 'Mars/Olympus']];
        yield 'a short password' => [['password' => 'short']];
        yield 'a blank name' => [['displayName' => ' ']];
        yield 'a blank company name' => [['companyName' => '']];
    }

    /**
     * @param array<string, mixed> $change
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedFinishes')]
    public function testARefusedFinishMakesNothingAndLeavesTheLinkUsable(array $change): void
    {
        $this->openSignup();
        $token = $this->requestALink();

        $this->postJson('/api/signup/'.$token.'/complete', $this->finish($change));

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([], $this->em()->getConnection()->fetchFirstColumn("SELECT id FROM \"user\" WHERE email = 'new@twes.local'"));
        $this->getJson('/api/signup/'.$token);
        self::assertResponseIsSuccessful();
    }

    public function testATakenCompanyNameIsRefused(): void
    {
        $this->openSignup();
        $this->createCompany('Nouvelle Société');
        $token = $this->requestALink();

        $this->postJson('/api/signup/'.$token.'/complete', $this->finish());

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testClosingSignupClosesTheLinksAlreadySent(): void
    {
        $this->openSignup();
        $token = $this->requestALink();
        $this->setPlatform(PlatformSettings::SIGNUP_ENABLED, false);

        $this->getJson('/api/signup/'.$token);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson('/api/signup/'.$token.'/complete', $this->finish());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnAddressThatGainedAnAccountMeanwhileCannotUseItsLink(): void
    {
        $this->openSignup();
        $token = $this->requestALink();
        $this->createUser(self::ADDRESS, 'someone-elses-password');

        $this->postJson('/api/signup/'.$token.'/complete', $this->finish());

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->login(self::ADDRESS, 'someone-elses-password');
        self::assertResponseIsSuccessful();
    }

    public function testOneAddressIsMailedOnceInAWindowWithTheSameAnswer(): void
    {
        $this->openSignup();
        $this->postJson('/api/signup', ['email' => self::ADDRESS]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertEmailCount(1);

        $this->postJson('/api/signup', ['email' => self::ADDRESS]);

        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        self::assertEmailCount(0);
    }

    public function testOneClientAsksForAFewLinksAndIsThenTurnedAway(): void
    {
        $this->openSignup();
        for ($i = 1; $i <= 5; ++$i) {
            $this->postJson('/api/signup', ['email' => \sprintf('person%d@twes.local', $i)]);
            self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);
        }

        $this->postJson('/api/signup', ['email' => 'person6@twes.local']);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        self::assertEmailCount(0);
    }

    private function openSignup(): void
    {
        $this->setPlatform(PlatformSettings::SIGNUP_ENABLED, true);
    }

    private function setPlatform(string $key, bool $value): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(), $key, SettingLevel::Platform, $value, null);
    }

    private function requestALink(): string
    {
        $this->postJson('/api/signup', ['email' => self::ADDRESS]);
        self::assertResponseStatusCodeSame(Response::HTTP_ACCEPTED);

        return self::tokenIn($this->lastMailTo(self::ADDRESS));
    }

    /**
     * @param array<string, mixed> $change
     *
     * @return array<string, mixed>
     */
    private function finish(array $change = []): array
    {
        return array_replace([
            'displayName' => 'Nadia',
            'password' => 'a-long-enough-password',
            'companyName' => 'Nouvelle Société',
            'countryCode' => 'TN',
            'timezone' => 'Africa/Tunis',
        ], $change);
    }

    private function lastMailTo(string $address): string
    {
        $message = self::getMailerMessage();
        self::assertInstanceOf(MimeEmail::class, $message);
        self::assertSame($address, $message->getTo()[0]->getAddress());

        return (string) $message->getHtmlBody();
    }

    /** The raw token exists only in the mail. */
    private static function tokenIn(string $html): string
    {
        $found = [];
        if (1 !== preg_match('#/signup/([0-9a-f]{64})#', $html, $found)) {
            self::fail('the signup mail carries no usable link');
        }

        return $found[1];
    }
}
