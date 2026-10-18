<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Module\Expenses\Domain\Model\Expense;
use App\Module\Expenses\Domain\Model\ExpenseCategory;
use App\Module\Expenses\Domain\Repository\ExpenseRepository;
use App\Module\Expenses\Domain\ValueObject\ExpenseAmounts;
use App\Module\Expenses\Domain\ValueObject\ExpenseDetails;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The reports over HTTP: the catalogue a role reads, a report's figures beside the period compared, what an address may
 * not ask, and the report as a file, which waits for a proof of who is asking as a list's file does.
 */
final class ReportsTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $now = new \DateTimeImmutable('2026-09-30');
        $energy = ExpenseCategory::create($this->company, 'Énergie', null, $now);
        $this->em()->persist($energy);
        $this->em()->flush();
        foreach (['2026-09-05' => '100', '2026-08-10' => '40'] as $day => $net) {
            $expense = Expense::create($this->company, new ExpenseDetails(new \DateTimeImmutable($day), 'Électricité'), ExpenseAmounts::one($net, 3), null, $energy, 3, RoundingPoint::PerRateGroup, $now);
            $expense->record($now);
            static::getContainer()->get(ExpenseRepository::class)->save($expense);
        }
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->createUser('books@twes.local', 'password-1234', $this->company, ['company.read', 'expense.read'], 'member');
        $this->login('books@twes.local', 'password-1234');
    }

    public function testTheCatalogueOffersTheReportsTheRoleReads(): void
    {
        $this->getJson($this->path().'/reports');

        self::assertResponseIsSuccessful();
        $keys = array_map(static fn (mixed $report): mixed => \is_array($report) ? $report['key'] ?? null : null, $this->arrayAt($this->json(), 'reports'));
        self::assertSame(['expenses-by-category', 'expenses-by-supplier'], $keys, 'no invoice.read, no takings; no audit.read, no activity');
    }

    public function testAReportIsItsFiguresBesideThePeriodCompared(): void
    {
        $this->getJson($this->path().'/reports/expenses-by-category?from=2026-09-01&to=2026-09-30&compare=previous');

        self::assertResponseIsSuccessful();
        $body = $this->json();
        self::assertSame(['2026-08-01', '2026-08-31'], [$body['comparisonFrom'] ?? null, $body['comparisonTo'] ?? null]);
        $row = $this->arrayAt($body, 'rows')[0] ?? null;
        self::assertIsArray($row);
        $cells = $row['cells'] ?? null;
        $previous = $row['previous'] ?? null;
        self::assertIsArray($cells);
        self::assertIsArray($previous);
        self::assertSame(['Énergie', '100.000', '40.000'], [$cells['category'] ?? null, $cells['gross'] ?? null, $previous['gross'] ?? null]);
        self::assertSame(['100.000', '40.000'], [$this->section($body, 'totals')['gross'] ?? null, $this->section($body, 'previousTotals')['gross'] ?? null]);
    }

    public function testAnAddressAskingWhatAReportCannotReadIsRefused(): void
    {
        $base = $this->path().'/reports/expenses-by-category';
        $refused = [
            $base => 'a report of a period needs one',
            $base.'?from=2026-09-01&to=2026-02-30' => 'a day the calendar has not',
            $base.'?from=2026-09-01&to=2027-09-30' => 'more than 366 days',
            $base.'?from=2026-09-01&to=2026-09-30&compare=decade' => 'a comparison there is not',
            $base.'?from=2026-09-01&to=2026-09-30&establishment=nope' => 'an establishment that is no identifier',
            $base.'?from=2026-09-01&to=2026-09-30&establishment='.Uuid::v7()->toRfc4122() => 'an establishment the company has not',
            $base.'?from=2026-09-01&to=2026-09-30&establishment='.$this->establishment() => 'an expense belongs to no establishment',
        ];
        foreach ($refused as $address => $why) {
            $this->client->request('GET', $address, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $why);
        }
        foreach (['/reports/takings?from=2026-09-01&to=2026-09-30' => 'a report the role may not read', '/reports/nothing-here?from=2026-09-01&to=2026-09-30' => 'a report nobody declared'] as $address => $why) {
            $this->getJson($this->path().$address);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $why);
        }
    }

    public function testTheFileIsTheReportsFiguresAndIsOnRecord(): void
    {
        $file = $this->path().'/reports/expenses-by-category/export.csv?from=2026-09-01&to=2026-09-30&compare=previous';
        $this->client->request('GET', $file);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null, 'a file leaves the company: who asks proves it first');

        $this->stepUp('password-1234');
        $this->client->request('GET', $file);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', (string) $this->client->getResponse()->headers->get('Content-Type'));
        self::assertStringContainsString('expenses-by-category_2026-09-01_2026-09-30.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(explode("\n", ltrim($this->client->getInternalResponse()->getContent(), "\xEF\xBB\xBF")), static fn (string $line): bool => '' !== trim($line)));
        self::assertSame([
            'category,net,taxes,gross,expenses,net.previous,taxes.previous,gross.previous,expenses.previous',
            'Énergie,100.000,0.000,100.000,1,40.000,0.000,40.000,1',
            'total,100.000,0.000,100.000,1,40.000,0.000,40.000,1',
        ], $lines);
        $recorded = static::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT changes FROM audit_log WHERE company_id = :company AND action = :action',
            ['company' => $this->company->getId()->toRfc4122(), 'action' => 'export.downloaded'],
        );
        self::assertCount(1, $recorded);
        self::assertIsString($recorded[0]['changes']);
        self::assertStringContainsString('report:expenses-by-category', $recorded[0]['changes']);

        $this->client->request('GET', $this->path().'/reports/expenses-by-category/export.xlsx?from=2026-09-01&to=2026-09-30');
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', $this->client->getInternalResponse()->getContent(), 'a workbook is a zip archive');
    }

    public function testAFileIsRefusedWhereTheReportIs(): void
    {
        $this->stepUp('password-1234');
        $this->client->request('GET', $this->path().'/reports/takings/export.csv?from=2026-09-01&to=2026-09-30');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a report the role may not read has no file either');
        $this->client->request('GET', $this->path().'/reports/expenses-by-category/export.csv?from=2026-09-01');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $globex = $this->createCompany('Globex');
        $this->client->request('GET', '/api/companies/'.$globex->getId()->toRfc4122().'/reports/expenses-by-category/export.csv?from=2026-09-01&to=2026-09-30');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s figures are no business of a stranger');

        $this->login('owner@twes.local', 'password-1234');
        $this->sendJson('PUT', $this->path().'/modules/reports', ['enabled' => false]);
        self::assertResponseIsSuccessful();
        $this->login('books@twes.local', 'password-1234');
        $this->stepUp('password-1234');
        $this->client->request('GET', $this->path().'/reports/expenses-by-category/export.csv?from=2026-09-01&to=2026-09-30');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'the reports switched off take their files with them');
    }

    private function establishment(): string
    {
        $id = static::getContainer()->get(Connection::class)->fetchOne('SELECT id FROM establishment WHERE company_id = ?', [$this->company->getId()->toRfc4122()]);
        self::assertIsString($id);

        return $id;
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
