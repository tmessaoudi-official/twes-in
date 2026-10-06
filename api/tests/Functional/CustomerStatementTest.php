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
use App\Fiscal\Domain\TaxComponentRepository;
use App\Fiscal\Domain\UnitRepository;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The statement of account of a customer: its invoices, credit notes and payments over a period, in the order they
 * happened, each with what the customer owed after it.
 */
final class CustomerStatementTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';

    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer('CLI-0001', 'Carthage Conseil')->getId()->toRfc4122();
    }

    /** Within a day: invoices, then credit notes, then payments. */
    public function testItListsWhatHappenedAndWhatTheCustomerOwedAfterEach(): void
    {
        $this->signedIn(['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write']);
        $first = $this->issue('100');
        $this->pay($first, '40');
        $this->creditNote($first, '10');
        $second = $this->issue('50');

        $this->getJson($this->path());

        self::assertResponseIsSuccessful();
        $statement = $this->json();
        self::assertSame(
            ['Carthage Conseil', '0.000', '150.000', '50.000', '100.000'],
            [$statement['customerName'], $statement['openingBalance'], $statement['totalDebit'], $statement['totalCredit'], $statement['closingBalance']],
        );
        $lines = $this->arrayAt($statement, 'lines');
        self::assertCount(4, $lines);
        self::assertSame(
            [['invoice', '100.000', '0.000', '100.000'], ['invoice', '50.000', '0.000', '150.000'], ['credit_note', '0.000', '10.000', '140.000'], ['payment', '0.000', '40.000', '100.000']],
            array_map(fn (mixed $line): array => [$this->stringAt($this->rowAt($line), 'kind'), $this->stringAt($this->rowAt($line), 'debit'), $this->stringAt($this->rowAt($line), 'credit'), $this->stringAt($this->rowAt($line), 'balance')], $lines),
        );
        self::assertSame($second, $this->stringAt($this->rowAt($lines[1]), 'documentId'));
        self::assertSame(
            $this->dueAcrossInvoices(),
            $this->stringAt($statement, 'closingBalance'),
            'what the statement ends on is what the customer\'s open invoices have due',
        );
    }

    public function testItStatesTheCreditLimitTheCustomerHasAndZeroWhenNoneIsSet(): void
    {
        $this->signedIn(['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write']);
        $this->getJson($this->path());
        self::assertSame('0.000', $this->stringAt($this->json(), 'creditLimit'), 'no limit set: zero, at the currency\'s scale');

        $this->setLimit('5000', null);
        $this->getJson($this->path());
        self::assertSame('5000.000', $this->stringAt($this->json(), 'creditLimit'), 'the company\'s limit applies to a customer with none of its own');

        $this->setLimit('1200.500', Uuid::fromString($this->customerId));
        $this->getJson($this->path());
        self::assertSame('1200.500', $this->stringAt($this->json(), 'creditLimit'), 'the customer\'s own limit wins');
        self::assertFalse($this->json()['overCreditLimit'], 'nothing owed yet');

        $this->issue('1000');
        $this->getJson($this->path());
        self::assertFalse($this->json()['overCreditLimit'], 'owing exactly less than the limit');
        $this->issue('300');
        $this->getJson($this->path());
        self::assertTrue($this->json()['overCreditLimit'], '1300 owed passes a limit of 1200.500');
        // Money the customer left on account is theirs against what they owe (audit 2026-10-06, E-10).
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $this->postJson($this->companyPath().'/customers/'.$this->customerId.'/credit-balance', ['amount' => '99.499', 'date' => $today]);
        self::assertResponseStatusCodeSame(201);
        $this->getJson($this->path());
        self::assertTrue($this->json()['overCreditLimit'], '1300 owed less 99.499 on account is 1200.501, still past 1200.500');
        $this->postJson($this->companyPath().'/customers/'.$this->customerId.'/credit-balance', ['amount' => '0.001', 'date' => $today]);
        $this->getJson($this->path());
        self::assertFalse($this->json()['overCreditLimit'], '1300 owed less 99.500 on account is exactly the limit');

        $this->setLimit('0', Uuid::fromString($this->customerId));
        $this->getJson($this->path());
        self::assertFalse($this->json()['overCreditLimit'], 'a limit of zero is no limit, whatever is owed');
    }

    public function testThePeriodSplitsTheOpeningBalanceFromTheLines(): void
    {
        $this->signedIn(['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write']);
        $old = $this->issue('100');
        $this->pay($old, '30');
        $recent = $this->issue('20');
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE invoice SET issue_date = '2026-01-10' WHERE id = :id", ['id' => $old]);
        $connection->executeStatement("UPDATE payment SET payment_date = '2026-01-20' WHERE invoice_id = :id", ['id' => $old]);
        $connection->executeStatement("UPDATE invoice SET issue_date = '2026-03-05' WHERE id = :id", ['id' => $recent]);

        $this->getJson($this->path().'?from=2026-02-01&to=2026-12-31');

        self::assertResponseIsSuccessful();
        $statement = $this->json();
        self::assertSame(['2026-02-01', '2026-12-31', '70.000', '90.000'], [$statement['from'], $statement['to'], $statement['openingBalance'], $statement['closingBalance']]);
        self::assertCount(1, $this->arrayAt($statement, 'lines'), 'only what happened in the period is a line');

        $this->getJson($this->path().'?from=2026-03-05&to=2026-03-05');
        self::assertSame(['70.000', '90.000', 1], [$this->json()['openingBalance'], $this->json()['closingBalance'], \count($this->arrayAt($this->json(), 'lines'))], 'a document dated the first day is a line, not part of the opening balance');

        $this->getJson($this->path().'?from=2026-01-01&to=2026-01-31');
        self::assertSame(['0.000', '70.000', 2], [$this->json()['openingBalance'], $this->json()['closingBalance'], \count($this->arrayAt($this->json(), 'lines'))]);
    }

    public function testWhatTheCustomerWithholdsIsNotOwed(): void
    {
        $withholding = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('RS1', $this->company->getId());
        self::assertNotNull($withholding);
        $holder = $this->customer('CLI-0003', 'Retenue', [$withholding->getId()])->getId()->toRfc4122();
        $this->signedIn(['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit']);
        $body = $this->invoiceBody('1000', $holder);
        $body['documentTaxComponentIds'] = null;
        $this->postJson($this->companyPath().'/invoices', $body);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->companyPath().'/invoices/'.$id.'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $issued = $this->json();
        self::assertNotSame([], $this->arrayAt($issued, 'withholdings'), 'the fixture withholds something');

        $this->getJson($this->companyPath().'/customers/'.$holder.'/statement');

        self::assertSame($this->stringAt($issued, 'amountDue'), $this->stringAt($this->json(), 'closingBalance'));
        self::assertNotSame($this->stringAt($issued, 'total'), $this->stringAt($this->json(), 'closingBalance'));
    }

    public function testItPrintsAsAPdfWithTheCompanyLogoAndEveryLine(): void
    {
        $this->signedIn(['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write']);
        $invoice = $this->issue('100');
        $this->pay($invoice, '40');
        static::getContainer()->get(CompanyLogo::class)->set($this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company'), 'logo.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true), null);

        $stored = $this->storedPdfs();
        $this->client->request('GET', $this->path().'/pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('content-type'));
        self::assertStringContainsString('statement-CLI-0001.pdf', (string) $response->headers->get('content-disposition'));
        $page = (string) $response->getContent();
        self::assertStringStartsWith('%PDF-', $page);
        foreach (['Relevé de compte', 'Carthage Conseil', 'Acme', '<img class="logo" src="data:image/png;base64,', '100,000', '40,000', '60,000'] as $expected) {
            self::assertStringContainsString($expected, $page);
        }
        self::assertSame($stored, $this->storedPdfs(), 'a statement is rendered on request, never stored (issuing an invoice stores that invoice\'s own PDF)');
    }

    public function testThePdfTakesTheSamePeriodAndTheSameRights(): void
    {
        $this->signedIn(['customer.read', 'invoice.read']);
        $this->client->request('GET', $this->path().'/pdf?from=2026-05-02&to=2026-05-01');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->client->request('GET', $this->companyPath().'/customers/'.self::ABSENT.'/statement/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testThePdfNeedsBothRightsAndASignedInCaller(): void
    {
        $this->client->request('GET', $this->path().'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);

        $this->signedIn(['invoice.read']);
        $this->client->request('GET', $this->path().'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCustomersDocumentsAndDraftsAreNotOnIt(): void
    {
        $other = $this->customer('CLI-0002', 'Autre')->getId()->toRfc4122();
        $this->signedIn(['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write']);
        $this->issue('100', $other);
        $this->postJson($this->companyPath().'/invoices', $this->invoiceBody('30', $this->customerId));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path());

        self::assertSame(['0.000', 0], [$this->json()['closingBalance'], \count($this->arrayAt($this->json(), 'lines'))]);
    }

    public function testItNeedsToReadBothTheCustomerAndItsInvoices(): void
    {
        $this->signedIn(['customer.read']);
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testItNeedsTheCustomerToReadInvoices(): void
    {
        $this->signedIn(['invoice.read']);
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnUnknownCustomerIsNotFound(): void
    {
        $this->signedIn(['customer.read', 'invoice.read']);
        $this->getJson($this->companyPath().'/customers/'.self::ABSENT.'/statement');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAPeriodThatIsNotOneIsRefused(): void
    {
        $this->signedIn(['customer.read', 'invoice.read']);

        foreach (['?from=yesterday', '?to=2026-13-40', '?from=2026-05-02&to=2026-05-01'] as $query) {
            $this->getJson($this->path().$query);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $query);
        }
    }

    public function testASignedOutCallerGetsNothing(): void
    {
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    /** @return array<string, mixed> */
    private function rowAt(mixed $line): array
    {
        self::assertIsArray($line);
        $row = [];
        foreach ($line as $key => $value) {
            self::assertIsString($key);
            $row[$key] = $value;
        }

        return $row;
    }

    /** What the customer's invoices still have due, added up where the invoices are kept. */
    private function dueAcrossInvoices(): string
    {
        $sum = $this->em()->getConnection()->fetchOne("SELECT COALESCE(SUM(amount_due), 0) FROM invoice WHERE customer_id = :c AND document_type = 'invoice' AND amount_due IS NOT NULL", ['c' => $this->customerId]);
        self::assertIsNumeric($sum);

        return number_format((float) $sum, 3, '.', '');
    }

    private function issue(string $net, ?string $customerId = null): string
    {
        $this->postJson($this->companyPath().'/invoices', $this->invoiceBody($net, $customerId ?? $this->customerId));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->companyPath().'/invoices/'.$id.'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    private function pay(string $invoiceId, string $amount): void
    {
        $this->postJson($this->companyPath().'/invoices/'.$invoiceId.'/payments', ['date' => (new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone())))->format('Y-m-d'), 'amount' => $amount, 'method' => 'transfer']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function creditNote(string $invoiceId, string $net): void
    {
        $this->postJson($this->companyPath().'/invoices/'.$invoiceId.'/credit-notes', ['creditNoteReason' => 'Retour']);
        $id = $this->stringAt($this->json(), 'id');
        $this->sendJson('PUT', $this->companyPath().'/invoices/'.$id, $this->invoiceBody($net, $this->customerId));
        self::assertResponseIsSuccessful();
        $this->postJson($this->companyPath().'/invoices/'.$id.'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    /** @return array<string, mixed> */
    private function invoiceBody(string $net, string $customerId): array
    {
        return [
            'customerId' => $customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => [],
            'lines' => [['description' => 'Prestation', 'quantity' => '1', 'unitId' => $this->unitId(), 'unitPriceNet' => $net, 'taxComponentIds' => []]],
        ];
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    /** @param list<Uuid> $defaultTaxes */
    private function customer(string $number, string $name, array $defaultTaxes = []): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, $number, new CustomerProfile(CustomerKind::Company, $name), null, $regime, $defaultTaxes, new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(): string
    {
        return $this->companyPath().'/customers/'.$this->customerId.'/statement';
    }

    private function storedPdfs(): int
    {
        $count = $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM file WHERE mime = \'application/pdf\'');
        self::assertIsInt($count);

        return $count;
    }

    /** The kernel reboots between requests: the company and the service are found again each time. */
    private function setLimit(string $amount, ?Uuid $customerId): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? throw new \LogicException('no company');
        static::getContainer()->get(ChangeSettings::class)->change(
            new SettingContext($company, customerId: $customerId),
            'credit.limit',
            null === $customerId ? SettingLevel::Company : SettingLevel::Customer,
            $amount,
            null,
        );
    }
}
