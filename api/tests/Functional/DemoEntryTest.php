<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Demo\Application\DemoSettings;
use App\Identity\Application\Account\DemoAccounts;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Essayer la démo »: a visitor with no account picks a country and is signed in as the owner of the sandbox ready for
 * it, shown as one address to every visitor, and counted as having just proved itself, since it has nothing to prove
 * itself with.
 */
final class DemoEntryTest extends ApiTestCase
{
    use MakesSandboxes;

    protected function setUp(): void
    {
        parent::setUp();
        // Its own client address, so no other test's entries count toward this one's budget.
        $this->client->setServerParameters(['REMOTE_ADDR' => '198.51.100.'.random_int(1, 254)]);
    }

    public function testAVisitorEntersTheReadySandboxOfTheCountryTheyPick(): void
    {
        $tunisian = $this->readySandbox('TN');
        $french = $this->readySandbox('FR');
        $this->em()->flush();

        $this->postJson('/api/auth/demo', ['country' => 'FR']);

        self::assertResponseIsSuccessful();
        $me = $this->json();
        self::assertSame(['email' => DemoAccounts::SHOWN_AS, 'demo' => true], array_intersect_key(self::user($me), ['email' => 1, 'demo' => 1]));
        self::assertSame(($french->getCompanyId() ?? self::fail('A ready sandbox has a company.'))->toRfc4122(), self::companyOf($me));
        self::assertSame([['FR', 'claimed'], ['FR', 'queued'], ['TN', 'ready']], $this->sandboxes(), 'the other country waits for its own visitor, and the claimed one is replaced');

        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame(DemoAccounts::SHOWN_AS, self::user($this->json())['email'], 'the sign-in holds');
        $this->getJson(\sprintf('/api/companies/%s/members', self::companyOf($me)));
        self::assertSame([DemoAccounts::SHOWN_AS], array_column($this->jsonList(), 'email'), 'the members list shows it the same');
        self::assertNotNull($tunisian->getCompanyId());
    }

    public function testTheSignInPageIsToldWhichCountriesItMayOfferWhileTheDemoIsOn(): void
    {
        $this->getJson('/api/auth/demo');

        self::assertResponseIsSuccessful();
        self::assertSame(['enabled' => true, 'countries' => [['code' => 'TN', 'company' => 'Carthage Conseil'], ['code' => 'FR', 'company' => 'Atelier Mercier']]], $this->json());

        $this->platform(DemoSettings::ENABLED, false);
        $this->getJson('/api/auth/demo');

        self::assertSame(['enabled' => false, 'countries' => []], $this->json());
    }

    public function testEveryoneElseIsShownAsThemselves(): void
    {
        $company = $this->createCompany('Quincaillerie');
        $this->createUser('owner@example.com', 'password-1234', $company);

        $this->login('owner@example.com', 'password-1234');

        self::assertSame(['email' => 'owner@example.com', 'demo' => false], array_intersect_key(self::user($this->json()), ['email' => 1, 'demo' => 1]));
    }

    public function testAVisitorNeverProvesThemselvesAgain(): void
    {
        $this->readySandbox('TN');
        $this->em()->flush();
        $this->postJson('/api/auth/demo', ['country' => 'TN']);
        $company = self::companyOf($this->json());

        // Making a role waits for a recent proof, which a throwaway account has nothing to give.
        $this->postJson(\sprintf('/api/companies/%s/roles', $company), ['name' => 'caissier', 'permissions' => ['customer.read']]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testNoneReadyAsksTheVisitorToComeBackShortly(): void
    {
        $this->postJson('/api/auth/demo', ['country' => 'TN']);

        self::assertResponseStatusCodeSame(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame('demo_busy', $this->stringAt($this->json(), 'error'));
        self::assertSame('30', $this->client->getResponse()->headers->get('Retry-After'));
        self::assertSame([['FR', 'queued'], ['TN', 'queued']], $this->sandboxes(), 'the pool is topped up on the way');
        $this->getJson('/api/auth/me');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testACountryWithNoDemoIsRefused(): void
    {
        $this->readySandbox('TN');
        $this->em()->flush();

        foreach ([['country' => 'DE'], ['country' => 7], []] as $body) {
            $this->postJson('/api/auth/demo', $body);

            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
            self::assertSame('unknown_country', $this->stringAt($this->json(), 'error'));
        }
        self::assertSame([['TN', 'ready']], $this->sandboxes());
    }

    public function testNobodyEntersWhileTheDemoIsSwitchedOff(): void
    {
        $this->readySandbox('TN');
        $this->em()->flush();
        $this->platform(DemoSettings::ENABLED, false);

        $this->postJson('/api/auth/demo', ['country' => 'TN']);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('demo_off', $this->stringAt($this->json(), 'error'));
        self::assertSame([['TN', 'ready']], $this->sandboxes(), 'the sandbox built before stays unclaimed');
    }

    public function testOneClientEntersTenTimesAnHourAtMost(): void
    {
        for ($entry = 1; $entry <= 10; ++$entry) {
            $this->postJson('/api/auth/demo', ['country' => 'DE']);
            self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        }

        $this->postJson('/api/auth/demo', ['country' => 'DE']);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
    }

    /**
     * @param array<string, mixed> $me
     *
     * @return array<mixed>
     */
    private static function user(array $me): array
    {
        return \is_array($me['user'] ?? null) ? $me['user'] : self::fail('The signed-in state names its user.');
    }

    /** @param array<string, mixed> $me */
    private static function companyOf(array $me): string
    {
        $company = $me['company'] ?? null;

        return \is_array($company) && \is_string($company['id'] ?? null) ? $company['id'] : self::fail('The signed-in state names its company.');
    }
}
