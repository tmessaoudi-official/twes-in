<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Subscriptions (docs/SPEC.md § 7, 2026-09-17): an operator sets a company's terms from the platform endpoints, the
 * company's standing follows from the dates on every request, and once grace ends unpaid its members may only read, or
 * only find the way out, as the operator chose. A stranger still learns nothing about the company.
 */
final class SubscriptionsTest extends ApiTestCase
{
    private bool $operatorCreated = false;

    public function testAnOperatorSetsReadsAndStopsACompanysSubscriptionAndEachChangeIsAudited(): void
    {
        $company = $this->createCompany('Acme');
        $this->signInOperator();

        $this->getJson($this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms(['trialEndsOn' => $this->daysFromToday(14)]));
        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame('trial', $body['stage']);
        self::assertSame('full', $body['access']);
        self::assertSame(15, $body['daysLeft']);
        self::assertSame($this->daysFromToday(14), $body['trialEndsOn']);
        self::assertNull($body['paidThrough']);

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([
            'paidThrough' => $this->daysFromToday(-30),
            'periodCount' => 6,
            'price' => '600.000',
            'currency' => 'TND',
            'graceDays' => 3,
            'unpaidMode' => 'locked',
        ]));
        self::assertResponseIsSuccessful();
        $this->getJson($this->subscriptionOf($company));
        $body = $this->json();
        self::assertSame('unpaid', $body['stage']);
        self::assertSame('locked', $body['access']);
        self::assertSame(6, $body['periodCount']);
        self::assertSame('600.000', $body['price']);

        $this->sendJson('DELETE', $this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $actions = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE entity_type = 'subscription' AND company_id = ? ORDER BY at, id",
            [$company->getId()->toRfc4122()],
        );
        self::assertSame(['subscription.set', 'subscription.set', 'subscription.stopped'], $actions);
    }

    public function testTermsTheDomainRefusesAreUnprocessable(): void
    {
        $company = $this->createCompany('Acme');
        $this->signInOperator();

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms());
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms(['paidThrough' => $this->daysFromToday(10), 'unpaidMode' => 'free']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms(['paidThrough' => '17/09/2026']));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->sendJson('PUT', '/api/platform/companies/0192f7c0-0000-7000-8000-000000000000/subscription', $this->terms(['paidThrough' => $this->daysFromToday(10)]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOnlyAnOperatorReachesTheSubscriptionEndpoints(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->login('owner@twes.local', 'password-1234');

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms(['paidThrough' => $this->daysFromToday(3650)]));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->getJson($this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testAnUnpaidReadOnlyCompanyIsReadButNotWrittenEvenByItsOwner(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(-30), 'graceDays' => 7, 'unpaidMode' => 'read_only']);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson($this->profileOf($company));
        self::assertResponseIsSuccessful();

        $this->sendJson('PUT', $this->profileOf($company), ['legalName' => 'Acme SARL']);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringContainsString('subscription_read_only', $this->stringAt($this->json(), 'detail'));

        $this->getJson('/api/auth/me');
        $me = $this->section($this->json(), 'company');
        self::assertSame('read_only', $me['access']);
        self::assertSame('unpaid', $this->section($me, 'subscription')['stage']);
    }

    public function testAnUnpaidLockedCompanyAnswersItsMembersWhyAndStrangersNothing(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $this->createUser('stranger@twes.local', 'password-1234', $this->createCompany('Globex'));
        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(-30), 'graceDays' => 0, 'unpaidMode' => 'locked']);

        $this->login('owner@twes.local', 'password-1234');
        $this->getJson($this->profileOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringContainsString('subscription_locked', $this->stringAt($this->json(), 'detail'));
        $this->getJson('/api/auth/me');
        self::assertResponseIsSuccessful();
        self::assertSame('locked', $this->section($this->json(), 'company')['access']);

        $this->login('stranger@twes.local', 'password-1234');
        $this->getJson($this->profileOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testGraceKeepsFullAccessAndTheCompanyWithoutASubscriptionIsNotManaged(): void
    {
        $inGrace = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $inGrace);
        $unmanaged = $this->createCompany('Globex');
        $this->createUser('globex@twes.local', 'password-1234', $unmanaged);
        $this->subscribe($inGrace, ['paidThrough' => $this->daysFromToday(-2), 'graceDays' => 7, 'unpaidMode' => 'locked']);
        $this->login('owner@twes.local', 'password-1234');

        $this->getJson('/api/auth/me');
        $me = $this->section($this->json(), 'company');
        self::assertSame('full', $me['access']);
        self::assertSame('grace', $this->section($me, 'subscription')['stage']);
        $this->sendJson('PUT', $this->profileOf($inGrace), ['legalName' => 'Acme SARL']);
        self::assertNotContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_FORBIDDEN, Response::HTTP_NOT_FOUND]);

        $this->login('globex@twes.local', 'password-1234');
        $this->getJson('/api/auth/me');
        $me = $this->section($this->json(), 'company');
        self::assertSame('full', $me['access']);
        self::assertNull($me['subscription']);
    }

    public function testThePlatformsDefaultsApplyWhereTheCompanysTermsAreSilent(): void
    {
        $company = $this->createCompany('Acme');
        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(-10)]);
        $this->signInOperator();

        $this->getJson($this->subscriptionOf($company));
        self::assertSame('read_only', $this->json()['access']);

        $this->sendJson('PUT', '/api/platform/settings/licensing.unpaid_mode', ['value' => 'locked']);
        self::assertResponseIsSuccessful();
        $this->getJson($this->subscriptionOf($company));
        self::assertSame('locked', $this->json()['access']);

        $this->sendJson('PUT', '/api/platform/settings/licensing.grace_days', ['value' => 30]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->subscriptionOf($company));
        self::assertSame('grace', $this->json()['stage']);
    }

    public function testThePlatformsCompanyListSaysWhereEachSubscriptionStands(): void
    {
        $managed = $this->createCompany('Acme');
        $this->createCompany('Globex');
        $this->subscribe($managed, ['trialEndsOn' => $this->daysFromToday(5)]);
        $this->signInOperator();

        $this->getJson('/api/platform/companies');
        $rows = array_column($this->jsonList(), null, 'name');
        $standing = $this->section($this->stringKeyedRow($rows, 'Acme'), 'subscription');
        self::assertSame('trial', $standing['stage']);
        self::assertSame(6, $standing['daysLeft']);
        self::assertNull($this->stringKeyedRow($rows, 'Globex')['subscription']);
    }

    /**
     * @param array<array-key, mixed> $rows
     *
     * @return array<string, mixed>
     */
    private function stringKeyedRow(array $rows, string $name): array
    {
        $row = $rows[$name] ?? null;
        self::assertIsArray($row);

        return $this->section(['row' => $row], 'row');
    }

    /** @param array<string, mixed> $terms */
    private function subscribe(Company $company, array $terms): void
    {
        $this->signInOperator();
        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms($terms));
        self::assertResponseIsSuccessful();
    }

    private function signInOperator(): void
    {
        if (!$this->operatorCreated) {
            $this->createUser('op@twes.local', 'password-1234', operator: true);
            $this->operatorCreated = true;
        }
        $this->login('op@twes.local', 'password-1234');
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function terms(array $overrides = []): array
    {
        return [
            'periodCount' => 1,
            'periodUnit' => 'month',
            'trialEndsOn' => null,
            'paidThrough' => null,
            'price' => null,
            'currency' => null,
            'graceDays' => null,
            'unpaidMode' => null,
            ...$overrides,
        ];
    }

    /** A day in the company's timezone, counted from today. */
    private function daysFromToday(int $days): string
    {
        return new \DateTimeImmutable('today', new \DateTimeZone('Africa/Tunis'))->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }

    private function subscriptionOf(Company $company): string
    {
        return '/api/platform/companies/'.$company->getId()->toRfc4122().'/subscription';
    }

    private function profileOf(Company $company): string
    {
        return '/api/companies/'.$company->getId()->toRfc4122().'/profile';
    }
}
