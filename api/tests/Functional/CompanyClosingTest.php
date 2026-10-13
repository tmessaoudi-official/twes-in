<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\SupportGrant;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;

/**
 * The owner closes the company (docs/plans/threat-model.plan.md, Decisions Log 2026-10-13 12:36 to 14:53). It stops
 * working at once: its members still read it and take its books, by GET and nothing else, until the end of the year it
 * closed plus the years its country keeps records; within the days the platform sets (7 by default) the owner reopens
 * it, after them the closing is final. Nothing an operator does reopens it.
 */
final class CompanyClosingTest extends ApiTestCase
{
    use RouteSweep;

    private const string PASSWORD = 'password-1234';

    private Company $company;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->owner = $this->createUser('owner@twes.local', self::PASSWORD, $this->company);
    }

    public function testNothingHappensUntilTheOwnerProvesWhoTheyAreAgain(): void
    {
        $this->login('owner@twes.local', self::PASSWORD);

        $this->postJson($this->path().'/close-down', []);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->stringAt($this->json(), 'error'));
        self::assertSame(Company::STATUS_ACTIVE, $this->companyStatus());
    }

    public function testOnlyTheOwnerClosesIt(): void
    {
        $this->createUser('manager@twes.local', self::PASSWORD, $this->company, ['*'], 'manager');
        $this->login('manager@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->postJson($this->path().'/close-down', []);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('owner_only', $this->stringAt($this->json(), 'error'));
        self::assertSame(Company::STATUS_ACTIVE, $this->companyStatus());
    }

    public function testClosingKeepsItUntilTheEndOfTheYearItClosedPlusItsCountrysYears(): void
    {
        $invitation = new Invitation($this->company, Email::fromString('later@twes.local'), $this->role(Role::OWNER), InvitationToken::generate(), new \DateTimeImmutable(), new \DateInterval('P7D'), $this->owner);
        $grant = new SupportGrant($this->company, $this->owner, 24, new \DateTimeImmutable());
        $this->em()->persist($invitation);
        $this->em()->persist($grant);
        $this->em()->flush();

        $this->closeDown();

        $body = $this->json();
        $year = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis')))->format('Y');
        self::assertSame(($year + 10).'-12-31', $this->stringAt($body, 'retainUntil'), 'Tunisia keeps every document ten years');
        self::assertEqualsWithDelta((new \DateTimeImmutable('+7 days'))->getTimestamp(), (new \DateTimeImmutable($this->stringAt($body, 'reopenUntil')))->getTimestamp(), 60);
        self::assertSame(Company::STATUS_CLOSED, $this->companyStatus());
        self::assertSame(1, $this->journalCount('company.closed_down'));
        self::assertSame(0, $this->numberOf('SELECT COUNT(*) FROM invitation WHERE company_id = ? AND accepted_at IS NULL'), 'an open invitation would let someone in');
        self::assertSame(1, $this->numberOf('SELECT COUNT(*) FROM support_grant WHERE company_id = ? AND ended_at IS NOT NULL'), 'support ends with it');
    }

    public function testAClosedCompanyIsReadAndItsBooksTakenButNothingChanges(): void
    {
        $this->closeDown();

        $this->client->request('GET', $this->path().'/customers');
        self::assertResponseIsSuccessful('the list reads');
        $this->client->request('GET', $this->path().'/exports/sales-journal.csv?from=2026-09-01&to=2026-09-30');
        self::assertResponseIsSuccessful('the books are taken');

        $this->postJson($this->path().'/customers', ['number' => 'CLI-0002', 'kind' => 'individual', 'name' => 'Sonia Ben Ali', 'taxRegime' => 'standard', 'defaultTaxComponentIds' => [], 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('company_closed', $this->stringAt($this->json(), 'error'));
    }

    public function testAReadThatTakesMoreThanReadingIsRefusedToo(): void
    {
        $this->closeDown();

        $this->client->request('GET', $this->path().'/support-access');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertStringContainsString('company_closed', (string) $this->client->getResponse()->getContent());
    }

    public function testAClosedCompanyPaysForNothingAndIsStillRead(): void
    {
        $this->closeDown();
        $this->em()->getConnection()->executeStatement("INSERT INTO subscription (id, company_id, period_count, period_unit, paid_until, grace_days, unpaid_mode, created_at, updated_at) VALUES (gen_random_uuid(), ?, 1, 'month', '2020-01-31', 0, 'locked', now(), now())", [$this->company->getId()->toRfc4122()]);

        $this->client->request('GET', $this->path().'/customers');

        self::assertResponseIsSuccessful('a lapsed subscription locks out a working company, never one kept for its records');
    }

    public function testEveryRouteThatWouldChangeAClosedCompanyIsRefused(): void
    {
        $this->closeDown();

        $router = static::getContainer()->get(RouterInterface::class);
        $answered = [];
        $swept = 0;
        foreach ($router->getRouteCollection()->all() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/api/companies/{companyId}') || 'api_company_reopen' === $name) {
                continue;
            }
            $path = self::pathOf($router, $name, $route, ['companyId' => $this->company->getId()->toRfc4122()]);
            foreach (self::methodsOf($route) as $method) {
                if ('GET' === $method) {
                    continue;
                }
                ++$swept;
                $this->sendJson($method, $path, []);
                $status = $this->client->getResponse()->getStatusCode();
                $body = json_decode((string) $this->client->getResponse()->getContent(), true);
                $error = \is_array($body) ? ($body['error'] ?? null) : null;
                if (Response::HTTP_FORBIDDEN !== $status || 'company_closed' !== $error) {
                    $answered[] = "$method $path ($name) answered $status";
                }
            }
        }

        self::assertGreaterThan(100, $swept, "the sweep reached $swept routes that write");
        self::assertSame([], $answered, 'these routes still act on a closed company');
    }

    public function testTheOwnerReopensItWithinTheDays(): void
    {
        $this->closeDown();

        $this->postJson($this->path().'/reopen', []);

        self::assertResponseIsSuccessful();
        self::assertSame(Company::STATUS_ACTIVE, $this->companyStatus());
        self::assertSame(1, $this->journalCount('company.reopened'));
        $this->postJson($this->path().'/customers', ['number' => 'CLI-0002', 'kind' => 'individual', 'name' => 'Sonia Ben Ali', 'taxRegime' => 'standard', 'defaultTaxComponentIds' => [], 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    public function testAfterTheDaysTheClosingIsFinal(): void
    {
        $this->closeDown();
        $this->closedDaysAgo(8);

        $this->postJson($this->path().'/reopen', []);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('reopen_period_over', $this->stringAt($this->json(), 'error'));
        self::assertSame(Company::STATUS_CLOSED, $this->companyStatus());
    }

    public function testTheDaysAreThePlatformsToSet(): void
    {
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext(), 'company.reopen_days', SettingLevel::Platform, 3, null);
        $this->closeDown();
        self::assertEqualsWithDelta((new \DateTimeImmutable('+3 days'))->getTimestamp(), (new \DateTimeImmutable($this->stringAt($this->json(), 'reopenUntil')))->getTimestamp(), 60);

        $this->closedDaysAgo(4);
        $this->postJson($this->path().'/reopen', []);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->closedDaysAgo(2);
        $this->postJson($this->path().'/reopen', []);
        self::assertResponseIsSuccessful();
    }

    public function testOnlyTheOwnerReopensIt(): void
    {
        $this->createUser('manager@twes.local', self::PASSWORD, $this->company, ['*'], 'manager');
        $this->closeDown();
        $this->login('manager@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);

        $this->postJson($this->path().'/reopen', []);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame(Company::STATUS_CLOSED, $this->companyStatus());
    }

    public function testAnOperatorApprovingOrRejectingItNeverReopensIt(): void
    {
        $this->closeDown();
        $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        foreach (['approve', 'reject'] as $decision) {
            $this->postJson('/api/platform/companies/'.$this->company->getId()->toRfc4122().'/'.$decision, []);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $decision);
            self::assertSame(Company::STATUS_CLOSED, $this->companyStatus(), $decision);
        }
    }

    private function closeDown(): void
    {
        $this->login('owner@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path().'/close-down', []);
        self::assertResponseIsSuccessful();
    }

    private function closedDaysAgo(int $days): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE company SET closed_down_at = ? WHERE id = ?', [(new \DateTimeImmutable("-$days days"))->format('Y-m-d H:i:s'), $this->company->getId()->toRfc4122()]);
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function companyStatus(): string
    {
        $status = $this->em()->getConnection()->fetchOne('SELECT status FROM company WHERE id = ?', [$this->company->getId()->toRfc4122()]);
        self::assertIsString($status);

        return $status;
    }

    private function role(string $name): Role
    {
        return $this->em()->getRepository(Role::class)->findOneBy(['company' => $this->company, 'name' => $name]) ?? throw new \LogicException("no $name role");
    }

    private function journalCount(string $action): int
    {
        return $this->numberOf('SELECT COUNT(*) FROM audit_log WHERE action = ? AND entity_id = ?', $action);
    }

    private function numberOf(string $sql, ?string $action = null): int
    {
        $parameters = null === $action ? [$this->company->getId()->toRfc4122()] : [$action, $this->company->getId()->toRfc4122()];
        $count = $this->em()->getConnection()->fetchOne($sql, $parameters);
        self::assertIsInt($count);

        return $count;
    }
}
