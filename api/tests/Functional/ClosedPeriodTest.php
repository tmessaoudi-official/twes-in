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
 * Closing a period (the accountant's third piece): once the books are closed through a day, no document is dated on or
 * before it — an invariant, not a setting. What carries a day into the books, and so is refused there:
 *   - every numbered document, through the one place that numbers them and gives them their day (invoices, credit
 *     notes, delivery notes and the rest);
 *   - a payment on an invoice recorded, or deleted, on a closed day; money received beyond what was due, on a closed day;
 *   - an expense entered in the books, or paid, on a closed day.
 * A draft is not in the books and stays free; stock, a supply day and an instrument held are not entries either.
 * The day moves forward only, and never reaches today: the day still being worked is never closed.
 */
final class ClosedPeriodTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;
    private ?string $categoryId = null;
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer()->getId()->toRfc4122();
        $this->vendor = Vendor::create($this->company, 'FRN-0001', new VendorProfile('Sotumag', paymentTermsDays: 30), new \DateTimeImmutable());
        $this->em()->persist($this->vendor);
        $this->em()->flush();
        $this->createUser('owner@twes.local', 'password-1234', $this->company);
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['company.read', 'invoice.read', 'payment.write'], 'clerk');
        $this->login('owner@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testTheBooksCloseForwardOnlyAndNeverOnTheDayStillWorked(): void
    {
        $this->getJson($this->closingPath());
        self::assertResponseIsSuccessful();
        self::assertSame([null, true], [$this->json()['closedThrough'] ?? null, $this->json()['writable'] ?? null], 'nothing closed yet');

        $this->sendJson('PUT', $this->closingPath(), ['closedThrough' => $this->day(0)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'today is still being worked');
        self::assertStringStartsWith('closedThrough:', $this->detail());

        $this->sendJson('PUT', $this->closingPath(), ['closedThrough' => $this->day(-5)]);
        self::assertResponseIsSuccessful();
        self::assertSame($this->day(-5), $this->json()['closedThrough'] ?? null);

        $this->sendJson('PUT', $this->closingPath(), ['closedThrough' => $this->day(-7)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a closed period never opens again');
        self::assertStringStartsWith('closedThrough:', $this->detail());

        $this->getJson($this->closingPath());
        self::assertSame($this->day(-5), $this->json()['closedThrough'] ?? null);
        $audited = $this->em()->getConnection()->fetchAllAssociative("SELECT changes FROM audit_log WHERE company_id = :c AND action = 'company.period_closed'", ['c' => $this->company->getId()->toRfc4122()]);
        self::assertCount(1, $audited, 'one closing, on record');
        self::assertSame('{"fields": ["closedThrough"]}', $audited[0]['changes'], 'the field it changed, never the day');
    }

    public function testOnlyWhoeverManagesTheCompanyClosesIt(): void
    {
        $this->login('clerk@twes.local', 'password-1234');

        $this->getJson($this->closingPath());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['writable'] ?? true);

        $this->sendJson('PUT', $this->closingPath(), ['closedThrough' => $this->day(-5)]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a permission not held answers as a stranger is answered');
    }

    public function testAPaymentIsNeitherRecordedNorDeletedOnAClosedDay(): void
    {
        $invoice = $this->issuedDaysAgo('100', 10);
        $this->postJson($this->invoicePath($invoice).'/payments', ['date' => $this->day(-8), 'amount' => '10', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $paymentId = $this->stringAt($this->json(), 'id');
        $this->close(-5);

        $this->postJson($this->invoicePath($invoice).'/payments', ['date' => $this->day(-5), 'amount' => '10', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the closing day itself is closed');
        self::assertStringStartsWith('closedPeriod:', $this->detail());

        $this->sendJson('DELETE', $this->invoicePath($invoice).'/payments/'.$paymentId, null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'nor is one taken out of a closed period');
        self::assertStringStartsWith('closedPeriod:', $this->detail());

        $this->postJson($this->invoicePath($invoice).'/payments', ['date' => $this->day(-4), 'amount' => '90', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the day after is open');

        $this->postJson($this->invoicePath($invoice).'/overpayments', ['amount' => '5', 'date' => $this->day(-6)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'money received beyond the due, on a closed day');
        self::assertStringStartsWith('closedPeriod:', $this->detail());
    }

    public function testAnExpenseIsNeitherEnteredNorPaidOnAClosedDay(): void
    {
        $this->close(-5);
        $late = $this->expense($this->day(-6));
        $this->postJson($this->expensePath($late).'/record', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a vendor invoice dated in the closed period is not entered');
        self::assertStringStartsWith('closedPeriod:', $this->detail());

        $current = $this->expense($this->day(-1));
        $this->postJson($this->expensePath($current).'/record', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->expensePath($current).'/pay', ['paymentMethod' => 'cash', 'paidOn' => $this->day(-6)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'nor paid on a closed day');
        self::assertStringStartsWith('closedPeriod:', $this->detail());
        $this->postJson($this->expensePath($current).'/pay', ['paymentMethod' => 'cash', 'paidOn' => $this->day(0)]);
        self::assertResponseIsSuccessful();
    }

    public function testANumberedDocumentIsNeverDatedInAClosedPeriod(): void
    {
        $open = $this->draft('100');
        $this->postJson($this->invoicePath($open).'/issue', null);
        self::assertResponseIsSuccessful('a closing in the past leaves today open');

        // The API never closes today; the number's own guard is what holds should the books be closed through it anyway.
        $this->em()->getConnection()->executeStatement('UPDATE company SET closed_through = :day WHERE id = :id', ['day' => $this->day(0), 'id' => $this->company->getId()->toRfc4122()]);
        $draft = $this->draft('50');
        $this->postJson($this->invoicePath($draft).'/issue', null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('closedPeriod:', $this->detail());
        $this->getJson($this->invoicePath($draft));
        self::assertSame([null, 'draft'], [$this->json()['number'] ?? null, $this->json()['status'] ?? null], 'it takes no number');
    }

    private function close(int $days): void
    {
        $this->sendJson('PUT', $this->closingPath(), ['closedThrough' => $this->day($days)]);
        self::assertResponseIsSuccessful();
    }

    private function detail(): string
    {
        $detail = $this->json()['detail'] ?? null;
        self::assertIsString($detail);

        return $detail;
    }

    /** The company's day, so many days from today. */
    private function day(int $days): string
    {
        return new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()))->modify(\sprintf('%+d days', $days))->format('Y-m-d');
    }

    private function draft(string $net): string
    {
        $this->postJson($this->companyPath().'/invoices', [
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Prestation', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => []]],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** An invoice issued today, then moved to a day in the past, as if it had been issued then. */
    private function issuedDaysAgo(string $net, int $days): string
    {
        $id = $this->draft($net);
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseIsSuccessful();
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET issue_date = :day, due_date = :day WHERE id = :id', ['day' => $this->day(-$days), 'id' => $id]);

        return $id;
    }

    private function expense(string $date): string
    {
        $categoryId = $this->categoryId ??= $this->category();
        $this->postJson($this->companyPath().'/expenses', [
            'date' => $date,
            'reference' => 'F-'.$date,
            'description' => 'Gasoil',
            'vendorId' => $this->vendor->getId()->toRfc4122(),
            'categoryId' => $categoryId,
            'amountNet' => '100',
            'taxComponentId' => null,
            'notes' => null,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a draft may carry any day');

        return $this->stringAt($this->json(), 'id');
    }

    private function category(): string
    {
        $this->postJson($this->companyPath().'/expense-categories', ['name' => 'Carburant', 'parentId' => null, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
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

    private function closingPath(): string
    {
        return $this->companyPath().'/closing';
    }

    private function invoicePath(string $id): string
    {
        return $this->companyPath().'/invoices/'.$id;
    }

    private function expensePath(string $id): string
    {
        return $this->companyPath().'/expenses/'.$id;
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
