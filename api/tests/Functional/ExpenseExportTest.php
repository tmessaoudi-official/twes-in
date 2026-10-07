<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/** The expenses list exported as a file, under the search, status, vendor and order the screen shows (docs/SPEC.md § 7, row 60). */
final class ExpenseExportTest extends ApiTestCase
{
    private Company $company;
    private string $vendorId;
    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag', paymentTermsDays: 30), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->flush();
        $this->vendorId = $vendor->getId()->toRfc4122();
        $this->createUser('buyer@twes.local', 'password-1234', $this->company, ['expense.read', 'expense.write'], 'member');
        $this->login('buyer@twes.local', 'password-1234');
        // A file waits for the person to have proved who they are again (docs/SPEC.md § 7, audit H-b2).
        $this->stepUp('password-1234');
        $this->postJson($this->path().'/expense-categories', ['name' => 'Loyers', 'parentId' => null, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $category = $this->stringAt($this->json(), 'id');
        $this->categoryId = $category;
        $this->draft('Gasoil septembre', '2026-09-10', '100.005', null);
        $recorded = $this->draft('Loyer septembre', '2026-09-01', '500', $category);
        $this->postJson($this->path().'/expenses/'.$recorded.'/record', null);
        self::assertResponseIsSuccessful();
    }

    public function testEveryExpenseIsOneRowWithItsFiguresAtTheCurrencyScale(): void
    {
        $lines = $this->csv('/exports/expenses.csv');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('expenses.csv', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        $header = array_map(strval(...), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''));
        self::assertSame(['date', 'reference', 'description', 'vendor', 'category', 'currency', 'amount_net', 'tax_amount', 'amount_gross', 'withholding_amount', 'amount_to_pay', 'status', 'due_date', 'paid_on', 'payment_method', 'notes'], $header);
        self::assertCount(3, $lines, 'the header and the two expenses');
        $rows = array_column(array_map(static fn (string $line): array => array_combine($header, str_getcsv($line, escape: '')), \array_slice($lines, 1)), null, 'description');
        $fuel = $rows['Gasoil septembre'];
        self::assertSame(['2026-09-10', 'Sotumag', 'TND', 'draft'], [$fuel['date'], $fuel['vendor'], $fuel['currency'], $fuel['status']]);
        self::assertSame(['100.005', '19.001', '119.006', '119.006', ''], [$fuel['amount_net'], $fuel['tax_amount'], $fuel['amount_gross'], $fuel['amount_to_pay'], $fuel['paid_on']], 'what is to be paid is not whether it was');
        self::assertSame('recorded', $rows['Loyer septembre']['status']);
        self::assertSame(['500.000', 'Loyers'], [$rows['Loyer septembre']['amount_net'], $rows['Loyer septembre']['category']]);
    }

    public function testTheSearchTheStatusAndTheOrderOfTheScreenNarrowAndSortTheFile(): void
    {
        self::assertCount(2, $this->csv('/exports/expenses.csv?status=recorded'));
        self::assertCount(2, $this->csv('/exports/expenses.csv?q=gasoil'));
        self::assertCount(3, $this->csv('/exports/expenses.csv?vendorId='.$this->vendorId));
        self::assertCount(1, $this->csv('/exports/expenses.csv?q=nobody-here'), 'the header alone');
        self::assertStringContainsString('Loyer', $this->csv('/exports/expenses.csv?order[date]=asc')[1]);
    }

    /** Row 197: the file is narrowed by every filter the screen sends, read by the same reader as the list. */
    public function testTheFileHoldsWhatTheListShowsUnderEveryFilter(): void
    {
        foreach ([
            'status[]=draft&status[]=recorded' => 2,
            'date[from]=2026-09-05' => 1,
            'amountGross[min]=200' => 1,
            'categoryId[]='.$this->categoryId => 1,
            'withheld=no' => 2,
            'paymentMethod[]=cash' => 0,
        ] as $query => $rows) {
            self::assertCount(1 + $rows, $this->csv('/exports/expenses.csv?'.$query), $query);
            $this->getJson($this->path().'/expenses?'.$query);
            self::assertResponseIsSuccessful($query);
            self::assertSame($rows, $this->jsonPage()['totalItems'], $query);
        }
    }

    public function testAnXlsxIsOfferedToo(): void
    {
        $this->client->request('GET', $this->path().'/exports/expenses.xlsx');

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('PK', $this->content(), 'a zip archive');
    }

    public function testWhoCannotReadExpensesIsAnsweredAsAStranger(): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), ['customer.read'], 'sales');
        $this->login('sales@twes.local', 'password-1234');

        $this->client->request('GET', $this->path().'/exports/expenses.csv');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    private function draft(string $description, string $date, string $net, ?string $categoryId): string
    {
        $tax = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->company->getId()->toRfc4122(), 'TVA19']);
        self::assertIsString($tax);
        $this->postJson($this->path().'/expenses', [
            'date' => $date,
            'reference' => null,
            'description' => $description,
            'vendorId' => $this->vendorId,
            'categoryId' => $categoryId,
            'amountNet' => $net,
            'taxComponentId' => $tax,
            'notes' => null,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @return list<string> */
    private function csv(string $suffix): array
    {
        $this->client->request('GET', $this->path().$suffix);

        return array_values(array_filter(explode("\n", $this->content()), static fn (string $line): bool => '' !== trim($line)));
    }

    private function content(): string
    {
        // A file response is deleted once sent: what the browser got is what the client kept.
        return $this->client->getInternalResponse()->getContent();
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
