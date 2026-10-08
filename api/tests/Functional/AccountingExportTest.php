<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The accountant's files (« Export comptable »): for a period, the sales journal (each issued invoice and credit note,
 * one row per tax it carries, a credit note's amounts negative so a column sums to the period), the payments journal,
 * the purchases journal (the expenses entered in the books) and the VAT summary by tax and rate. Drafts are never in
 * them. A period is two days, both required.
 */
final class AccountingExportTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;
    private string $vendorId;
    private string $categoryId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer()->getId()->toRfc4122();
        $vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag', paymentTermsDays: 30), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->flush();
        $this->vendorId = $vendor->getId()->toRfc4122();
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['company.read', 'invoice.read', 'expense.read'], 'reader');
        $this->login('owner@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        $this->stepUp('password-1234');
        $this->postJson($this->path().'/expense-categories', ['name' => 'Carburant', 'parentId' => null, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->categoryId = $this->stringAt($this->json(), 'id');
    }

    public function testTheSalesJournalHasARowPerTaxOfEachIssuedDocumentOfThePeriod(): void
    {
        $this->september();

        $rows = $this->rows('sales-journal', '2026-09-01', '2026-09-30');

        self::assertSame(['date', 'number', 'type', 'customer_number', 'customer', 'customer_identifiers', 'tax_code', 'tax_rate', 'base', 'tax', 'document_net', 'document_total', 'currency'], array_keys($rows[0] ?? []));
        self::assertSame([
            ['2026-09-10', 'invoice', 'TVA19', '100.000', '19.000', '119.000'],
            ['2026-09-12', 'invoice', 'TVA19', '200.000', '38.000', '238.000'],
            ['2026-09-20', 'credit_note', 'TVA19', '-200.000', '-38.000', '-238.000'],
        ], array_map(static fn (array $row): array => [$row['date'], $row['type'], $row['tax_code'], $row['base'], $row['tax'], $row['document_total']], $rows), 'the draft and the October invoice are not in September; a credit note counts against it');
        self::assertSame(['CLI-0001', 'Carthage Conseil', 'TND'], [$rows[0]['customer_number'], $rows[0]['customer'], $rows[0]['currency']]);
        self::assertSame('19', rtrim(rtrim($rows[0]['tax_rate'], '0'), '.'));
        self::assertNotSame('', $rows[0]['number']);
    }

    public function testThePaymentsAndPurchasesJournalsHoldWhatTheBooksHoldForThePeriod(): void
    {
        $this->september();

        $payments = $this->rows('payments-journal', '2026-09-01', '2026-09-30');
        self::assertSame(['date', 'invoice_number', 'customer_number', 'customer', 'method', 'reference', 'amount', 'currency'], array_keys($payments[0] ?? []));
        self::assertSame([['2026-09-15', 'transfer', '50.000']], array_map(static fn (array $row): array => [$row['date'], $row['method'], $row['amount']], $payments));

        $purchases = $this->rows('purchases-journal', '2026-09-01', '2026-09-30');
        self::assertSame(['date', 'reference', 'vendor', 'description', 'category', 'tax_code', 'tax_rate', 'net', 'tax', 'gross', 'withholding', 'status', 'paid_on', 'payment_method', 'currency'], array_keys($purchases[0] ?? []));
        self::assertSame([['2026-09-05', 'Sotumag', 'Carburant', 'TVA19', '100.000', '19.000', '119.000', 'recorded']], array_map(static fn (array $row): array => [$row['date'], $row['vendor'], $row['category'], $row['tax_code'], $row['net'], $row['tax'], $row['gross'], $row['status']], $purchases), 'a draft is not in the books, and October is not September');
    }

    public function testTheVatSummaryAddsTheSalesAndThePurchasesByTaxAndRate(): void
    {
        $this->september();

        $rows = $this->rows('vat-summary', '2026-09-01', '2026-09-30');

        self::assertSame(['tax_code', 'tax_rate', 'sales_base', 'sales_tax', 'purchases_base', 'purchases_tax'], array_keys($rows[0] ?? []));
        self::assertSame([['TVA19', '100.000', '19.000', '100.000', '19.000']], array_map(static fn (array $row): array => [$row['tax_code'], $row['sales_base'], $row['sales_tax'], $row['purchases_base'], $row['purchases_tax']], $rows));
    }

    public function testAPeriodIsTwoDaysInOrder(): void
    {
        foreach (['', '?from=2026-09-01', '?from=2026-09-30&to=2026-09-01', '?from=2026-02-30&to=2026-03-01', '?from=2025-01-01&to=2026-09-30'] as $query) {
            $this->client->request('GET', $this->path().'/exports/sales-journal.csv'.$query);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $query);
        }
    }

    public function testTheFilesAreTheAccountantsPermission(): void
    {
        $this->login('reader@twes.local', 'password-1234');
        $this->stepUp('password-1234');

        $this->client->request('GET', $this->path().'/exports/sales-journal.csv?from=2026-09-01&to=2026-09-30');

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'reading invoices is not exporting the books');
    }

    /** September's documents, payments and expenses, and a few outside it or outside the books. */
    private function september(): void
    {
        $first = $this->issued('100', '2026-09-10');
        $second = $this->issued('200', '2026-09-12');
        $this->issued('300', '2026-10-02');
        $this->draft('400');

        $this->postJson($this->path().'/invoices/'.$first.'/payments', ['date' => $this->today(), 'amount' => '50', 'method' => 'transfer']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->em()->getConnection()->executeStatement("UPDATE payment SET payment_date = '2026-09-15' WHERE invoice_id = :id", ['id' => $first]);

        $this->postJson($this->path().'/invoices/'.$second.'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $credit = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path().'/invoices/'.$credit.'/issue', null);
        self::assertResponseIsSuccessful();
        $this->dated($credit, '2026-09-20');

        $this->expense('2026-09-05', true);
        $this->expense('2026-09-06', false);
        $this->expense('2026-10-01', true);
    }

    /** @return list<array<string, string>> the file's rows by its header */
    private function rows(string $subject, string $from, string $to): array
    {
        $this->client->request('GET', $this->path().'/exports/'.$subject.'.csv?from='.$from.'&to='.$to);
        self::assertResponseIsSuccessful();
        $lines = array_values(array_filter(explode("\n", $this->client->getInternalResponse()->getContent()), static fn (string $line): bool => '' !== trim($line)));
        $header = array_map(strval(...), str_getcsv(ltrim($lines[0] ?? '', "\xEF\xBB\xBF"), escape: ''));

        return array_map(static fn (string $line): array => array_combine($header, array_map(strval(...), str_getcsv($line, escape: ''))), \array_slice($lines, 1));
    }

    private function draft(string $net): string
    {
        $this->postJson($this->path().'/invoices', [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Prestation', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => [$this->taxId('TVA19')]]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function issued(string $net, string $day): string
    {
        $id = $this->draft($net);
        $this->postJson($this->path().'/invoices/'.$id.'/issue', null);
        self::assertResponseIsSuccessful();
        $this->dated($id, $day);

        return $id;
    }

    /** An issued document moved to a day, as if it had been issued then. */
    private function dated(string $id, string $day): void
    {
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET issue_date = :day WHERE id = :id', ['day' => $day, 'id' => $id]);
    }

    private function expense(string $date, bool $inTheBooks): void
    {
        $this->postJson($this->path().'/expenses', [
            'date' => $date,
            'reference' => 'F-'.$date,
            'description' => 'Gasoil',
            'vendorId' => $this->vendorId,
            'categoryId' => $this->categoryId,
            'amountNet' => '100',
            'taxComponentId' => $this->taxId('TVA19'),
            'notes' => null,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        if ($inTheBooks) {
            $this->postJson($this->path().'/expenses/'.$this->stringAt($this->json(), 'id').'/record', null);
            self::assertResponseIsSuccessful();
        }
    }

    private function today(): string
    {
        return new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
    }

    private function taxId(string $code): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM tax_component WHERE company_id = ? AND code = ?', [$this->company->getId()->toRfc4122(), $code]);
        self::assertIsString($id, $code);

        return $id;
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    private function customer(): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        $customer = Customer::create($company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function path(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
