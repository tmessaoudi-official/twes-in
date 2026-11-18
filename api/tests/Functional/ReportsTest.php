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
use App\Module\Reports\Application\ReportComparison;
use App\Module\Reports\Application\ReportExportSettings;
use App\Module\Reports\Application\ReportPeriod;
use App\Shared\Application\Languages;
use App\Shared\Application\Spreadsheet\SpreadsheetFormat;
use App\Shared\Application\Spreadsheet\SpreadsheetWriter;
use App\Shared\Application\Spreadsheet\UnwritableSpreadsheet;
use App\Shared\Infrastructure\Storage\ScratchFiles;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $comparisons = implode(', ', array_map(static fn (ReportComparison $each): string => $each->value, ReportComparison::cases()));
        $refused = [
            $base => ['a report of a period needs one', 'from', 'value_required', []],
            $base.'?from=2026-09-01' => ['a period needs its last day too', 'to', 'value_required', []],
            $base.'?from=2026-09-01&to=2026-02-30' => ['a day the calendar has not', 'to', 'not_a_date', []],
            $base.'?from=2026-09-01&to=2027-09-30' => ['more than 366 days', 'to', 'period_longest', ['days' => ReportPeriod::LONGEST_DAYS]],
            // The route's own parameter constraint refuses first, with no reason yet; the file's address is read by the request itself.
            $base.'?from=2026-09-01&to=2026-09-30&compare=decade' => ['a comparison there is not', 'compare', null, []],
            $base.'/export.csv?from=2026-09-01&to=2026-09-30&compare=decade' => ['a comparison there is not, in the file', 'compare', 'not_one_of', ['choices' => $comparisons]],
            $base.'?from=2026-09-01&to=2026-09-30&establishment=nope' => ['an establishment that is no identifier', 'establishment', null, []],
            $base.'/export.csv?from=2026-09-01&to=2026-09-30&establishment=nope' => ['an establishment that is no identifier, in the file', 'establishment', 'invalid_value', []],
            $base.'?from=2026-09-01&to=2026-09-30&establishment='.Uuid::v7()->toRfc4122() => ['an establishment the company has not, or no longer', 'establishment', 'unknown_choice', []],
            $base.'?from=2026-09-01&to=2026-09-30&establishment='.$this->establishment() => ['an expense belongs to no establishment', 'establishment', 'not_by_establishment', []],
            $base.'?from=2026-09-01&to=2026-09-30&mergedProducts=apart' => ['an option the report does not take', 'mergedProducts', 'invalid_value', []],
            $base.'/export.csv?from=2026-09-01&to=2026-09-30&netBeforeDiscount=shown' => ['an option the report does not take, in the file', 'netBeforeDiscount', 'invalid_value', []],
            $base.'/documents?from=2026-09-01&to=2026-09-30' => ['the documents of a row name the row', 'row', null, []],
            $base.'/documents?from=2026-09-01&to=2026-09-30&row=x&establishment='.$this->establishment() => ['the documents of a report no establishment narrows', 'establishment', 'not_by_establishment', []],
        ];
        foreach ($refused as $address => [$why, $field, $reason, $params]) {
            $this->client->request('GET', $address, server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $why);
            self::assertSame([$reason, $params], $this->refusalOf($field), $why);
        }
        $this->login('owner@twes.local', 'password-1234');
        $this->client->request('GET', $this->path().'/reports/aged-balance?from=2026-09-01&to=2026-09-30', server: ['HTTP_ACCEPT' => 'application/json']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a report of today takes no period');
        self::assertSame(['invalid_value', []], $this->refusalOf('from'), 'a report of today takes no period');
        $this->login('books@twes.local', 'password-1234');
        foreach (['/reports/takings?from=2026-09-01&to=2026-09-30' => 'a report the role may not read', '/reports/nothing-here?from=2026-09-01&to=2026-09-30' => 'a report nobody declared'] as $address => $why) {
            $this->getJson($this->path().$address);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $why);
        }
    }

    public function testAPeriodARefusedReportCannotReadIsRefusedOnItsDayWithItsReason(): void
    {
        $base = $this->path().'/reports/expenses-by-category';

        $this->getJson($base.'?from=2026-09-30&to=2026-09-01');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'to', 'reason' => 'on_or_after', 'params' => ['day' => '2026-09-30']]], $this->refusals(), 'a period ends on its first day at the earliest');

        $this->getJson($base.'?from=2026-09-01&to=2027-09-30');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'to', 'reason' => 'period_longest', 'params' => ['days' => 366]]], $this->refusals(), 'a report reads a year and a day at most');

        $narrowed = '?from=2026-09-01&to=2026-09-30&establishment='.$this->establishment();
        $this->getJson($base.$narrowed);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'establishment', 'reason' => 'not_by_establishment', 'params' => []]], $this->refusals(), 'an expense belongs to no establishment: the report reads the whole company');

        $this->stepUp('password-1234');
        $this->client->request('GET', $base.'/export.csv?from=2026-09-30&to=2026-09-01');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'to', 'reason' => 'on_or_after', 'params' => ['day' => '2026-09-30']]], $this->refusals(), 'the file is refused as the report is, in words the export menu says');
        $this->client->request('GET', $base.'/export.csv'.$narrowed);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'establishment', 'reason' => 'not_by_establishment', 'params' => []]], $this->refusals(), 'and so is its establishment');
    }

    public function testAReportTakesTheOptionsItsCatalogueOffersAndItsFileFollowsThem(): void
    {
        $this->login('owner@twes.local', 'password-1234');
        $this->getJson($this->path().'/reports');
        self::assertResponseIsSuccessful();
        $options = [];
        foreach ($this->arrayAt($this->json(), 'reports') as $report) {
            self::assertIsArray($report);
            self::assertIsString($report['key'] ?? null);
            $options[$report['key']] = $report['options'] ?? null;
        }
        $sales = [['name' => 'netBeforeDiscount', 'choices' => ['hidden', 'shown']], ['name' => 'mergedProducts', 'choices' => ['kept', 'apart']]];
        self::assertSame([$sales, $sales, [['name' => 'netBeforeDiscount', 'choices' => ['hidden', 'shown']]], []], [$options['sales-by-product'] ?? null, $options['sales-by-family'] ?? null, $options['sales-by-customer'] ?? null, $options['takings'] ?? null]);

        $asked = '?from=2026-09-01&to=2026-09-30&netBeforeDiscount=shown&mergedProducts=apart';
        $this->getJson($this->path().'/reports/sales-by-product'.$asked);
        self::assertResponseIsSuccessful();
        $keys = array_map(static fn (mixed $column): mixed => \is_array($column) ? $column['key'] ?? null : null, $this->arrayAt($this->json(), 'columns'));
        self::assertContains('net_before_discount', $keys);
        self::assertContains('merged_into', $keys);

        $this->stepUp('password-1234');
        $this->client->request('GET', $this->path().'/reports/sales-by-product/export.csv'.$asked);
        self::assertResponseIsSuccessful();
        $header = explode("\n", ltrim($this->client->getInternalResponse()->getContent(), "\xEF\xBB\xBF"))[0];
        self::assertStringContainsString('merged_into', $header, 'the file is the report the screen shows');
        self::assertStringContainsString('net_before_discount', $header);

        foreach (['/export.csv', '/documents', ''] as $where) {
            $this->client->request('GET', $this->path().'/reports/sales-by-product'.$where.'?from=2026-09-01&to=2026-09-30&row=none&mergedProducts=sideways', server: ['HTTP_ACCEPT' => 'application/json']);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $where);
            self::assertSame(['not_one_of', ['choices' => 'kept, apart']], $this->refusalOf('mergedProducts'), $where);
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

    /**
     * A company that asks for it gets its files headed in the words its screen shows, its own wording included, in the
     * language the screen speaks: the period compared said after each figure's word, the totals line named.
     */
    public function testTheFileIsHeadedInTheScreensWordsWhenTheCompanyAsks(): void
    {
        $this->login('owner@twes.local', 'password-1234');
        $this->stepUp('password-1234');
        $file = $this->path().'/reports/expenses-by-category/export.csv?from=2026-09-01&to=2026-09-30&compare=previous';
        self::assertSame('category,net,taxes,gross,expenses,net.previous,taxes.previous,gross.previous,expenses.previous', $this->fileLines($file)[0], 'keys until the company asks otherwise');

        $this->sendJson('PUT', $this->path().'/settings/'.ReportExportSettings::HEADERS, ['level' => 'company', 'value' => ReportExportSettings::WORDS]);
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path().'/texts/fr/screen:reports/reports.columns.net', ['value' => 'Net HT', 'version' => 0]);
        self::assertResponseIsSuccessful();

        self::assertSame([
            ['Catégorie', 'Net HT', 'Taxes', 'TTC', 'Dépenses', 'Net HT (Période comparée)', 'Taxes (Période comparée)', 'TTC (Période comparée)', 'Dépenses (Période comparée)'],
            ['Énergie', '100.000', '0.000', '100.000', '1', '40.000', '0.000', '40.000', '1'],
            ['Total', '100.000', '0.000', '100.000', '1', '40.000', '0.000', '40.000', '1'],
        ], array_map(static fn (string $line): array => str_getcsv($line, escape: ''), $this->fileLines($file)));
        self::assertSame(
            ['Category', 'Net', 'Taxes', 'Gross', 'Expenses', 'Net (Period compared)', 'Taxes (Period compared)', 'Gross (Period compared)', 'Expenses (Period compared)'],
            str_getcsv($this->fileLines($file, 'en')[0], escape: ''),
            'the language the screen asks for; the company worded only the French',
        );
    }

    /** Every report's file is worded in every language the screens speak: no key, no value left unfilled, no heading twice. */
    public function testEveryReportsFileIsWordedInEveryLanguage(): void
    {
        $this->login('owner@twes.local', 'password-1234');
        $this->stepUp('password-1234');
        $this->sendJson('PUT', $this->path().'/settings/'.ReportExportSettings::HEADERS, ['level' => 'company', 'value' => ReportExportSettings::WORDS]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path().'/reports');
        self::assertResponseIsSuccessful();
        $reports = $this->arrayAt($this->json(), 'reports');
        self::assertGreaterThan(10, \count($reports), 'the owner reads every report');

        foreach (static::getContainer()->get(Languages::class)->interface() as $language) {
            foreach ($reports as $report) {
                self::assertIsArray($report);
                self::assertIsString($key = $report['key'] ?? null);
                $period = true === ($report['periodic'] ?? null) ? '?from=2026-09-01&to=2026-09-30&compare=previous' : '';
                $headings = str_getcsv($this->fileLines($this->path().'/reports/'.$key.'/export.csv'.$period, $language)[0], escape: '');
                foreach ($headings as $heading) {
                    self::assertIsString($heading);
                    self::assertDoesNotMatchRegularExpression('/^[a-z0-9_.()=,]+$|\{|reports\./', $heading, "$key in $language");
                }
                self::assertSame($headings, array_values(array_unique($headings)), "$key in $language: a heading twice");
            }
        }
    }

    /** @return list<string> the file's lines, its byte-order mark and blank lines left out */
    private function fileLines(string $file, string $language = 'fr'): array
    {
        $this->client->request('GET', $file, server: ['HTTP_ACCEPT_LANGUAGE' => $language]);
        self::assertResponseIsSuccessful($file);

        return array_values(array_filter(explode("\n", ltrim($this->client->getInternalResponse()->getContent(), "\xEF\xBB\xBF")), static fn (string $line): bool => '' !== trim($line)));
    }

    /** @return iterable<string, array{int, int}> */
    public static function rooms(): iterable
    {
        yield 'the disk out of room: said as the storage full' => [\PHP_INT_MAX, 507];
        yield 'room left: a moment to wait' => [0, 503];
    }

    /** A report's file that could not be written answers what kept it, as a list's does. */
    #[DataProvider('rooms')]
    public function testAReportFileThatCouldNotBeWrittenSaysWhy(int $roomMinimum, int $status): void
    {
        $this->stepUp('password-1234');
        // A fresh container whose writer fails and whose disk reads as asked, kept for the download.
        $this->client->getKernel()->shutdown();
        $this->client->getKernel()->boot();
        $this->client->disableReboot();
        static::getContainer()->set(ScratchFiles::class, new ScratchFiles($roomMinimum));
        static::getContainer()->set(SpreadsheetWriter::class, new class implements SpreadsheetWriter {
            public function write(string $path, SpreadsheetFormat $format, iterable $rows, array $textColumns = []): void
            {
                throw new UnwritableSpreadsheet('No room to write it.');
            }
        });

        $this->client->request('GET', $this->path().'/reports/expenses-by-category/export.csv?from=2026-09-01&to=2026-09-30');

        self::assertResponseStatusCodeSame($status);
        self::assertSame(507 === $status ? '/problems/storage-full' : null, 507 === $status ? ($this->json()['type'] ?? null) : null);
        if (503 === $status) {
            self::assertResponseHeaderSame('retry-after', '30');
        }
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
