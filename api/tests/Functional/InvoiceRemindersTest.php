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
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\RemindLateInvoices;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Staged reminders (DOC-20, MON-16): once an invoice is late by a stage of the company's calendar, at or after the
 * company's hour, the stage is recorded once and the people who issue invoices are told it is time to remind the
 * customer. Nothing is sent to the customer yet: the stage is reached, not sent.
 */
final class InvoiceRemindersTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;
    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer('CLI-0001', 'Carthage Conseil')->getId()->toRfc4122();
        $this->userId = $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'payment.write'], 'member')->getId()->toRfc4122();
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['invoice.read'], 'reader');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testAStageIsRecordedOnceAtTheCompanysHourAndTheIssuersAreTold(): void
    {
        $invoice = $this->issue('100');
        $this->dueDaysAgo($invoice, 8);

        $this->runAt('08:30');
        self::assertSame([], $this->reminders(), 'before the company\'s hour (9 by default), nothing yet');

        $this->runAt('09:05');
        self::assertSame([[$invoice, 1, 8]], $this->reminders(), '8 days late reaches the first stage of 7, 15, 30');
        $told = $this->toldTo($this->userId);
        self::assertCount(1, $told);
        self::assertSame('invoice.reminder_due', $told[0]['type']);
        $said = array_intersect_key($told[0]['payload'], array_flip(['amount_due', 'currency', 'customer', 'days_late', 'stage']));
        ksort($said);
        self::assertSame(['amount_due' => '100.000', 'currency' => 'TND', 'customer' => 'Carthage Conseil', 'days_late' => 8, 'stage' => 1], $said);

        $this->runAt('10:00');
        self::assertCount(1, $this->reminders(), 'the same stage is never recorded twice');
        self::assertCount(1, $this->toldTo($this->userId), 'nor told twice');
    }

    public function testAnInvoiceAlreadyFarLateRecordsOnlyTheHighestStageReached(): void
    {
        $invoice = $this->issue('100');
        $this->dueDaysAgo($invoice, 40);

        $this->runAt('09:00');

        self::assertSame([[$invoice, 3, 40]], $this->reminders(), 'one reminder, the third, not three at once');
        self::assertCount(1, $this->toldTo($this->userId));
    }

    public function testDueTodayIsNotLateAndAPaidInvoiceIsNotReminded(): void
    {
        $dueToday = $this->issue('100');
        $this->dueDaysAgo($dueToday, 0);
        $paid = $this->issue('50');
        $this->dueDaysAgo($paid, 20);
        $this->pay($paid, '50');

        $this->runAt('12:00');

        self::assertSame([], $this->reminders());
    }

    public function testACustomerLeftOutOfRemindersAndACompanyCalendarAreBothHeard(): void
    {
        $this->set('reminders.stages', '3, 10', null);
        $this->set('reminders.hour', 7, null);
        $first = $this->issue('100');
        $this->dueDaysAgo($first, 4);
        $otherCustomer = $this->customer('CLI-0002', 'Sans relance')->getId()->toRfc4122();
        $this->set('reminders.enabled', false, Uuid::fromString($otherCustomer));
        $second = $this->issue('100', $otherCustomer);
        $this->dueDaysAgo($second, 30);

        $this->runAt('07:10');

        self::assertSame([[$first, 1, 4]], $this->reminders(), 'the company\'s own stages and hour; a customer switched off is left out');
    }

    public function testRemindersSwitchedOffForTheCompanyRecordNothing(): void
    {
        $this->set('reminders.enabled', false, null);
        $invoice = $this->issue('100');
        $this->dueDaysAgo($invoice, 20);

        $this->runAt('18:00');

        self::assertSame([], $this->reminders());
    }

    public function testTheInvoiceSaysWhichStagesItReached(): void
    {
        $invoice = $this->issue('100');
        $this->dueDaysAgo($invoice, 16);
        $this->runAt('09:00');

        $this->getJson($this->companyPath().'/invoices/'.$invoice.'/reminders');

        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        self::assertCount(1, $rows);
        self::assertSame([2, 16], [$rows[0]['stage'] ?? null, $rows[0]['daysLate'] ?? null]);
        self::assertIsString($rows[0]['reachedOn'] ?? null);
    }

    /** Runs the reminders as the hourly task would, at a time of the company's own day, today. */
    private function runAt(string $time): void
    {
        $zone = new \DateTimeZone($this->company->getTimezone());
        Clock::set(new MockClock(new \DateTimeImmutable('today '.$time, $zone)));
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        static::getContainer()->get(RemindLateInvoices::class)->handle($company);
    }

    /** @return list<array{0: string, 1: int, 2: int}> invoice, stage and days late, by invoice then stage */
    private function reminders(): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative('SELECT invoice_id, stage, days_late FROM invoice_reminder WHERE company_id = :c ORDER BY invoice_id, stage', ['c' => $this->company->getId()->toRfc4122()]);
        $out = [];
        foreach ($rows as $row) {
            self::assertIsString($row['invoice_id']);
            self::assertIsInt($row['stage']);
            self::assertIsInt($row['days_late']);
            $out[] = [$row['invoice_id'], $row['stage'], $row['days_late']];
        }

        return $out;
    }

    /** @return list<array{type: string, payload: array<mixed>}> */
    private function toldTo(string $userId): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative("SELECT type, payload FROM inbox_item WHERE recipient_id = :u AND type = 'invoice.reminder_due'", ['u' => $userId]);
        $out = [];
        foreach ($rows as $row) {
            self::assertIsString($row['type']);
            self::assertIsString($row['payload']);
            $payload = json_decode($row['payload'], true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($payload);
            $out[] = ['type' => $row['type'], 'payload' => $payload];
        }

        return $out;
    }

    private function dueDaysAgo(string $invoiceId, int $days): void
    {
        $day = new \DateTimeImmutable('today', new \DateTimeZone($this->company->getTimezone()))->modify(\sprintf('-%d days', $days));
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET due_date = :day WHERE id = :id', ['day' => $day->format('Y-m-d'), 'id' => $invoiceId]);
    }

    private function set(string $key, mixed $value, ?Uuid $customerId): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        static::getContainer()->get(ChangeSettings::class)->change(
            new SettingContext($company, customerId: $customerId),
            $key,
            null === $customerId ? SettingLevel::Company : SettingLevel::Customer,
            $value,
            null,
        );
    }

    private function issue(string $net, ?string $customerId = null): string
    {
        $this->postJson($this->companyPath().'/invoices', [
            'customerId' => $customerId ?? $this->customerId,
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
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->companyPath().'/invoices/'.$id.'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    private function pay(string $invoiceId, string $amount): void
    {
        $this->postJson($this->companyPath().'/invoices/'.$invoiceId.'/payments', ['date' => new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d'), 'amount' => $amount, 'method' => 'transfer']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    private function customer(string $number, string $name): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        $customer = Customer::create($company, $number, new CustomerProfile(CustomerKind::Company, $name), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
