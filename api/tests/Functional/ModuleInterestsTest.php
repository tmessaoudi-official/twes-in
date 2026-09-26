<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\ModuleRegistry\Domain\ModuleInterest;
use App\Tenancy\Domain\Company;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * « Me prévenir » (docs/SPEC.md § 7, 2026-09-26 10:08, row 150): a company asks to be told when a planned module
 * arrives, the operator reads the demand across every company, and the start of the api tells the companies whose
 * module now ships.
 */
final class ModuleInterestsTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testWhoMaySwitchModulesAsksToBeToldAndWithdraws(): void
    {
        $this->signedIn($this->company, ['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path('quotes').'/interest', ['interested' => true]);

        self::assertResponseIsSuccessful();
        self::assertSame(['quotes', 'v1', true, false], [$this->json()['key'], $this->json()['planned'], $this->json()['interested'], $this->json()['enabled']]);
        $this->getJson($this->path());
        $rows = array_column($this->jsonList(), null, 'key');
        self::assertTrue($rows['quotes']['interested']);
        self::assertFalse($rows['zakat']['interested']);
        self::assertArrayNotHasKey('interested', $rows['customers'], 'a real module is switched, never waited for');
        self::assertSame(['module.interest_recorded'], $this->auditActions());

        $this->sendJson('PUT', $this->path('quotes').'/interest', ['interested' => false]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['interested']);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM module_interest'));
        self::assertSame(['module.interest_recorded', 'module.interest_withdrawn'], $this->auditActions());
    }

    public function testAModuleThatShipsIsRefusedAnUnknownOneIsAbsentAndTheAnswerIsYesOrNo(): void
    {
        $this->signedIn($this->company, ['company.read', 'company.settings']);

        $this->sendJson('PUT', $this->path('customers').'/interest', ['interested' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertStringContainsString('customers is already available', $this->stringAt($this->json(), 'detail'));
        $this->sendJson('PUT', $this->path('payroll').'/interest', ['interested' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path('quotes').'/interest', ['interested' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM module_interest'));
    }

    public function testOnlyWhoMaySwitchModulesAsksAndNeverForAnotherCompany(): void
    {
        $globex = $this->createCompany('Globex');
        $this->signedIn($this->company, ['company.read', 'invoice.write']);

        $this->sendJson('PUT', $this->path('quotes').'/interest', ['interested' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', '/api/companies/'.$globex->getId()->toRfc4122().'/modules/quotes/interest', ['interested' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM module_interest'));
    }

    public function testTheOperatorReadsTheDemandAcrossEveryCompanyMostAskedFirst(): void
    {
        $globex = $this->createCompany('Globex');
        $this->waits($this->company, 'quotes');
        $this->waits($globex, 'quotes');
        $this->waits($globex, 'zakat');
        $this->createUser('op@twes.local', self::PASSWORD, operator: true);
        $this->login('op@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();

        $this->getJson('/api/platform/module-demand');

        self::assertResponseIsSuccessful();
        $demand = $this->jsonList();
        self::assertCount(23, $demand, 'every planned module, asked for or not');
        self::assertSame([['quotes', 2], ['zakat', 1], ['accounting_export', 0]], array_map(static fn (array $row) => [$row['key'], $row['companies']], \array_slice($demand, 0, 3)));
        self::assertSame(['key' => 'quotes', 'labelKey' => 'modules.quotes', 'planned' => 'v1', 'companies' => 2], $demand[0]);
    }

    public function testACompanyMemberDoesNotReadTheDemand(): void
    {
        $this->signedIn($this->company, ['*']);

        $this->getJson('/api/platform/module-demand');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // No planned module ships in this build, so a wait for a module that already does stands for the release that
    // brought it: the members who may switch it on are told, once, and the wait no longer reads as one.
    public function testTheStartOfTheApiTellsTheCompaniesWhoseModuleArrivedOnce(): void
    {
        $owner = $this->createUser('owner@acme.test', self::PASSWORD, $this->company, ['*']);
        $this->createUser('clerk@acme.test', self::PASSWORD, $this->company, ['company.read', 'invoice.write'], 'member');
        $this->waits($this->company, 'customers');
        $this->waits($this->company, 'quotes');

        self::assertStringContainsString('Told 1 waiting company', $this->announce());
        self::assertSame('', $this->announce(), 'a restart tells nobody again');

        $told = $this->em()->getConnection()->fetchAllAssociative("SELECT recipient_id, payload FROM inbox_item WHERE type = 'module.arrived'");
        self::assertCount(1, $told);
        self::assertSame($owner->getId()->toRfc4122(), $told[0]['recipient_id']);
        self::assertSame(['module' => 'customers', 'label_key' => 'modules.customers', 'company' => 'Acme'], json_decode($this->stringAt($told[0], 'payload'), true));
        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM module_interest WHERE module_key = 'quotes' AND announced_at IS NULL"));
    }

    public function testTheApiImageTellsTheArrivalsAfterItsMigrationsAtEveryStart(): void
    {
        $entrypoint = (string) file_get_contents(\dirname(__DIR__, 3).'/infra/api/docker-entrypoint.sh');

        $migrate = strpos($entrypoint, 'doctrine:migrations:migrate');
        $announce = strpos($entrypoint, 'php bin/console app:modules:announce-arrivals');
        self::assertNotFalse($migrate);
        self::assertNotFalse($announce, 'nothing else ever runs the command');
        self::assertGreaterThan($migrate, $announce);
    }

    private function announce(): string
    {
        $tester = new CommandTester((new Application(static::$kernel ?? throw new \LogicException('kernel not booted')))->find('app:modules:announce-arrivals'));
        self::assertSame(0, $tester->execute([]));

        return trim($tester->getDisplay());
    }

    private function waits(Company $company, string $key): void
    {
        $this->em()->persist(new ModuleInterest($company, $key, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        /** @var list<string> $actions */
        $actions = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE action LIKE 'module.interest%' ORDER BY action");

        return $actions;
    }

    private function path(?string $key = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/modules'.(null === $key ? '' : '/'.$key);
    }

    /** @param list<string> $permissions */
    private function signedIn(Company $company, array $permissions): void
    {
        $this->createUser('admin@twes.local', self::PASSWORD, $company, $permissions, 'member');
        $this->login('admin@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
    }
}
