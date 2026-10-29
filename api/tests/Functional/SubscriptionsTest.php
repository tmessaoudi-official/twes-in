<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Licensing\Application\ManageSubscriptions;
use App\Licensing\Domain\Model\Subscription;
use App\Licensing\Domain\ValueObject\BillingPeriod;
use App\Licensing\Domain\ValueObject\PeriodUnit;
use App\Licensing\Domain\ValueObject\SubscriptionTerms;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
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
            'version' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $this->getJson($this->subscriptionOf($company));
        $body = $this->json();
        self::assertSame('unpaid', $body['stage']);
        self::assertSame('locked', $body['access']);
        self::assertSame(6, $body['periodCount']);
        self::assertSame('600.000', $body['price']);

        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version=2');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $actions = $this->em()->getConnection()->fetchFirstColumn(
            "SELECT action FROM audit_log WHERE entity_type = 'subscription' AND company_id = ? ORDER BY at, id",
            [$company->getId()->toRfc4122()],
        );
        self::assertSame(['subscription.set', 'subscription.set', 'subscription.stopped'], $actions);
    }

    /** The journal names the terms that changed, never what they hold: a price or a date stays out of it. */
    public function testTheAuditNamesTheTermsChangedAndNeverTheirValues(): void
    {
        $company = $this->createCompany('Acme');
        $this->signInOperator();
        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms(['trialEndsOn' => $this->daysFromToday(14)]));
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([
            'paidThrough' => $this->daysFromToday(30),
            'price' => '600.000',
            'currency' => 'TND',
            'graceDays' => 3,
            'version' => 1,
        ]));
        self::assertResponseIsSuccessful();
        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version=2');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $changes = array_map(
            static fn (mixed $json): mixed => json_decode(\is_string($json) ? $json : 'null', true),
            $this->em()->getConnection()->fetchFirstColumn(
                "SELECT changes FROM audit_log WHERE entity_type = 'subscription' AND company_id = ? ORDER BY at, id",
                [$company->getId()->toRfc4122()],
            ),
        );
        self::assertSame([
            ['fields' => ['period', 'trialEndsAt']],
            ['fields' => ['trialEndsAt', 'paidUntil', 'price', 'currency', 'graceDays']],
            ['fields' => ['period', 'paidUntil', 'price', 'currency', 'graceDays']],
        ], $changes);
    }

    /** What the company pays, or until when, is the owners' business: each is told when an operator changes or stops it. */
    public function testTheOwnersAreToldWhenAnOperatorSetsOrStopsTheirSubscription(): void
    {
        $company = $this->createCompany('Acme');
        $this->createUser('owner@twes.local', 'password-1234', $company);
        $coOwner = $this->createUser('co-owner@twes.local', 'password-1234');
        $role = $this->em()->getRepository(Role::class)->findOneBy(['name' => Role::OWNER, 'company' => $company]);
        self::assertInstanceOf(Role::class, $role);
        $acme = $this->em()->find(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $acme);
        $this->em()->persist(new Membership($coOwner, $acme, $role));
        $this->em()->flush();
        $this->createUser('clerk@twes.local', 'password-1234', $company, ['sale.write'], 'clerk');

        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(30)]);
        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(30)]);
        self::assertSame(['{"fields": ["period", "paidUntil"]}', '{"fields": []}'], $this->em()->getConnection()->fetchFirstColumn("SELECT changes FROM audit_log WHERE action = 'subscription.set' ORDER BY at, id"), 'the same terms again change nothing, though read back in another time zone');
        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version='.$this->seen($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $told = $this->em()->getConnection()->fetchAllAssociative(
            "SELECT u.email, i.type, i.company_id, i.payload FROM inbox_item i JOIN \"user\" u ON u.id = i.recipient_id WHERE i.type LIKE 'subscription.%' ORDER BY i.type, u.email",
        );
        $expected = [];
        foreach (['subscription.set', 'subscription.stopped'] as $type) {
            foreach (['co-owner@twes.local', 'owner@twes.local'] as $email) {
                $expected[] = ['email' => $email, 'type' => $type, 'company_id' => $company->getId()->toRfc4122(), 'payload' => '{"company":"Acme"}'];
            }
        }
        self::assertSame($expected, $told, 'each owner, once per change: terms set again unchanged tell nothing, and the clerk is not told');
    }

    /** Stopping erases the terms, so it names the version it was read at, as every delete of an edited record does. */
    public function testStoppingNamesTheVersionItWasReadAt(): void
    {
        $company = $this->createCompany('Acme');
        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(30)]);
        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(60)]);

        $this->sendJson('DELETE', $this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a stop naming no version');
        self::assertContains(['propertyPath' => 'version', 'reason' => 'value_required', 'params' => []], $this->refusals());

        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version=one');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a version that is no number');

        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version=1');
        $this->assertChangedSince('terms read before the last change');
        $this->getJson($this->subscriptionOf($company));
        self::assertResponseIsSuccessful('and the terms are still held');

        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version=2');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version=2');
        $this->assertChangedSince('terms another operator stopped since');
    }

    public function testTheTermsNameTheVersionTheyWereReadAtAndNoneReadAsNoneIsZero(): void
    {
        $company = $this->createCompany('Acme');
        $this->signInOperator();
        $paid = ['paidThrough' => $this->daysFromToday(30)];

        $without = $this->terms($paid);
        unset($without['version']);
        $this->sendJson('PUT', $this->subscriptionOf($company), $without);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'terms without the version they were read at');
        self::assertContains(['propertyPath' => 'version', 'reason' => 'value_required', 'params' => []], $this->refusals());

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([...$paid, 'version' => 2]));
        $this->assertChangedSince('terms read on a subscription that is not there');

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms($paid));
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version'], 'none read is 0, and the first terms start it at 1');

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([...$paid, 'graceDays' => 9]));
        $this->assertChangedSince('none read, while another operator set terms since');

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([...$paid, 'graceDays' => 5, 'version' => 1]));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['version']);

        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([...$paid, 'graceDays' => 9, 'version' => 1]));
        $this->assertChangedSince('terms read before the change');
        $this->getJson($this->subscriptionOf($company));
        self::assertSame(5, $this->json()['graceDays'], 'and nothing of them is written');

        $this->sendJson('DELETE', $this->subscriptionOf($company).'?version=2');
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([...$paid, 'version' => 2]));
        $this->assertChangedSince('terms read on a subscription stopped since');
        $this->getJson($this->subscriptionOf($company));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'and it stays stopped');
    }

    /** The terms an operator read are checked against the row held, not against what this request read before it. */
    public function testTermsAreCheckedAgainstTheRowTheyHold(): void
    {
        $company = $this->createCompany('Acme');
        $this->subscribe($company, ['paidThrough' => $this->daysFromToday(30)]);
        $operator = $this->createUser('other-op@twes.local', 'password-1234', operator: true);
        $id = $this->stringAt($this->json(), 'companyId');
        $held = $this->em()->getRepository(Subscription::class)->findOneBy(['company' => $company->getId()]);
        self::assertNotNull($held, 'read as the request would');
        $this->em()->getConnection()->executeStatement('UPDATE subscription SET version = version + 1, grace_days = 4 WHERE company_id = ?', [$id]);

        $terms = new SubscriptionTerms(new BillingPeriod(1, PeriodUnit::Month), null, new \DateTimeImmutable('+30 days'), null, null, 6);
        static::getContainer()->get(ManageSubscriptions::class)->set($company->getId(), $terms, $operator->getId(), 2);

        self::assertSame([6, 3], [$this->em()->getConnection()->fetchOne('SELECT grace_days FROM subscription WHERE company_id = ?', [$id]), $this->seen($company)]);
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

        $this->sendJson('PUT', $this->profileOf($company), ['version' => $this->storedVersion('company', $company->getId()->toRfc4122()), 'legalName' => 'Acme SARL']);
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
        $this->sendJson('PUT', $this->subscriptionOf($company), $this->terms([...$terms, 'version' => $this->seen($company)]));
        self::assertResponseIsSuccessful();
    }

    /** The version of the company's subscription, 0 while it has none: what an operator who just read it sends. */
    private function seen(Company $company): int
    {
        $version = $this->em()->getConnection()->fetchOne('SELECT version FROM subscription WHERE company_id = ?', [$company->getId()->toRfc4122()]);

        if (false === $version) {
            return 0;
        }
        self::assertIsInt($version);

        return $version;
    }

    private function assertChangedSince(string $why): void
    {
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $why);
        self::assertSame('/problems/record-changed', $this->stringAt($this->json(), 'type'));
    }

    private function signInOperator(): void
    {
        if (!$this->operatorCreated) {
            $this->createUser('op@twes.local', 'password-1234', operator: true);
            $this->operatorCreated = true;
        }
        $this->login('op@twes.local', 'password-1234');
        $this->stepUp('password-1234');
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
            'version' => 0,
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
