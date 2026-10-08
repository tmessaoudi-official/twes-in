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
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A customer's credit balance (docs/SPEC.md § 7): money paid beyond an invoice (a « trop-perçu »), kept for them and
 * applied to an invoice later. Applying records an ordinary payment on the invoice, and deleting that payment gives the credit back.
 */
final class CustomerCreditBalanceTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer('CLI-0001', 'Carthage Conseil')->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'payment.write'], 'member');
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read'], 'reader');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testMoneyPaidBeyondAnInvoiceIsKeptToTheCustomersCreditNamingThatInvoice(): void
    {
        $this->getJson($this->creditPath());
        self::assertResponseIsSuccessful();
        self::assertSame(['0.000', []], [$this->json()['balance'], $this->json()['entries']]);
        $invoice = $this->issue('100');

        $this->postJson($this->invoicePath($invoice).'/overpayments', ['amount' => '500', 'date' => $this->today()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'what is still due is paid on the invoice first');

        $this->pay($invoice, '100');
        $this->postJson($this->invoicePath($invoice).'/overpayments', ['amount' => '500', 'date' => $this->today(), 'reference' => 'VIR-9', 'notes' => 'Réglé deux fois']);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->creditPath());
        $credit = $this->json();
        self::assertSame(['500.000', 1], [$credit['balance'], \count($this->arrayAt($credit, 'entries'))]);
        $entry = $this->rowOf($this->arrayAt($credit, 'entries')[0]);
        self::assertSame(['overpayment', '500.000', 'VIR-9', $invoice], [$entry['kind'], $entry['amount'], $entry['reference'], $entry['invoiceId']]);

        $this->getJson($this->companyPath().'/customers/'.$this->customerId.'/statement');
        self::assertSame('500.000', $this->json()['creditBalance'], 'the statement states what the customer has to their credit, apart from its lines');
    }

    public function testMoneyBeforeAnyInvoiceIsNoLongerKeptAsCredit(): void
    {
        // An advance before an invoice needs a deposit invoice (docs/fiscal TN.md and FR.md § 2b): the customer-level
        // entry is gone, and only an invoice takes what was paid beyond it.
        $this->postJson($this->creditPath(), ['amount' => '500', 'date' => $this->today()]);
        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testWhatIsNotMoneyReceivedBeyondAnIssuedInvoiceIsRefused(): void
    {
        $invoice = $this->issue('100');
        $this->pay($invoice, '100');
        foreach ([['0', $this->today(), 'amount'], ['-5', $this->today(), 'amount'], ['10.0005', $this->today(), 'amount'], ['10', '2999-01-01', 'date'], ['10', '2000-01-01', 'date']] as [$amount, $date, $field]) {
            $this->postJson($this->invoicePath($invoice).'/overpayments', ['amount' => $amount, 'date' => $date]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $amount.' '.$date);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }

        $draft = $this->draft('50');
        $this->postJson($this->invoicePath($draft).'/overpayments', ['amount' => '10', 'date' => $this->today()]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft was never due');

        $this->getJson($this->creditPath());
        self::assertSame('0.000', $this->json()['balance']);
    }

    public function testCreditIsAppliedToAnInvoiceAsAPaymentAndNeverBeyondTheBalanceOrWhatIsDue(): void
    {
        $this->overpaid('300');
        $invoice = $this->issue('250');

        $this->postJson($this->invoicePath($invoice).'/apply-credit', ['amount' => '100']);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $applied = $this->json();
        self::assertSame(['partially_paid', '100.000', '150.000'], [$applied['status'], $applied['amountPaid'], $applied['amountDue']]);
        $payments = $this->arrayAt($applied, 'payments');
        self::assertCount(1, $payments);
        $payment = $payments[0];
        self::assertIsArray($payment);
        self::assertSame(['other', '100.000'], [$payment['method'], $payment['amount']]);
        $this->getJson($this->creditPath());
        self::assertSame(['200.000', ['applied', 'overpayment']], [$this->json()['balance'], array_column($this->arrayAt($this->json(), 'entries'), 'kind')]);
        self::assertSame($invoice, $this->rowOf($this->arrayAt($this->json(), 'entries')[0])['invoiceId'] ?? null);

        $this->postJson($this->invoicePath($invoice).'/apply-credit', ['amount' => '151']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'only 150 is due');
        $big = $this->issue('1000');
        $this->postJson($this->invoicePath($big).'/apply-credit', ['amount' => '201']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'only 200 is to their credit');
        self::assertStringContainsString('amount', (string) $this->client->getResponse()->getContent());

        $this->postJson($this->invoicePath($invoice).'/apply-credit', ['amount' => '150']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('paid', $this->json()['status']);
        $this->postJson($this->invoicePath($invoice).'/apply-credit', ['amount' => '1']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'nothing is due on a paid invoice');
        $this->getJson($this->creditPath());
        self::assertSame('50.000', $this->json()['balance']);
    }

    public function testWithNoAmountAsMuchCreditIsAppliedAsTheInvoiceAndTheBalanceAllow(): void
    {
        $this->overpaid('300');
        $small = $this->issue('100');
        $big = $this->issue('1000');

        $this->postJson($this->invoicePath($small).'/apply-credit', []);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['paid', '100.000'], [$this->json()['status'], $this->json()['amountPaid']], 'the invoice takes what it is due, the balance having more');

        $this->postJson($this->invoicePath($big).'/apply-credit', []);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['partially_paid', '200.000'], [$this->json()['status'], $this->json()['amountPaid']], 'the balance is spent when the invoice is due more');

        $this->postJson($this->invoicePath($big).'/apply-credit', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'nothing is left to apply');
        self::assertStringContainsString('amount', (string) $this->client->getResponse()->getContent());
    }

    public function testDeletingThePaymentThatAppliedCreditGivesTheCreditBack(): void
    {
        $this->overpaid('300');
        $invoice = $this->issue('250');
        $this->postJson($this->invoicePath($invoice).'/apply-credit', ['amount' => '250']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $paymentId = $this->stringAt($this->rowOf($this->arrayAt($this->json(), 'payments')[0]), 'id');
        $this->getJson($this->creditPath());
        self::assertSame('50.000', $this->json()['balance']);

        $this->sendJson('DELETE', $this->invoicePath($invoice).'/payments/'.$paymentId, null);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->getJson($this->creditPath());
        self::assertSame(['300.000', ['overpayment']], [$this->json()['balance'], array_column($this->arrayAt($this->json(), 'entries'), 'kind')]);
    }

    public function testItNeedsTheRightsAndBelongsToItsCompany(): void
    {
        $invoice = $this->overpaid('10');

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->creditPath());
        self::assertResponseIsSuccessful();
        $this->postJson($this->invoicePath($invoice).'/overpayments', ['amount' => '10', 'date' => $this->today()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'recording money takes payment.write');

        $this->getJson($this->companyPath().'/customers/0192c3a4-0000-7000-8000-000000000000/credit-balance');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /**
     * Audit 2026-10-06, D-5 / G-15: another company's REAL customer and invoice, reached through this company's path,
     * answer as an unknown one does; an id that exists nowhere would stay 404 even if a lookup stopped scoping by company.
     */
    public function testAnotherCompanysCustomerAndInvoiceAnswerAsUnknownOnesDo(): void
    {
        [$theirCustomer, $theirInvoice, $theirPaid] = $this->inGlobex(function (): array {
            $paid = $this->overpaid('300');

            return [$this->customerId, $this->issue('200'), $paid];
        });
        $theirs = $this->companyPath().'/customers/'.$theirCustomer.'/credit-balance';

        $this->getJson($theirs);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'their balance');
        $this->postJson($this->invoicePath($theirPaid).'/overpayments', ['amount' => '10', 'date' => $this->today()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'money kept for their customer');
        $this->postJson($this->invoicePath($theirInvoice).'/apply-credit', ['amount' => '100']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'their invoice paid from a credit');
    }

    /**
     * Runs the work as a person of a second company, Globex, with its own customer, then signs Acme's person back in.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inGlobex(\Closure $work): mixed
    {
        [$acme, $acmeCustomer] = [$this->company, $this->customerId];
        $this->company = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->customerId = $this->customer('CLI-0001', 'Globex Client')->getId()->toRfc4122();
        $this->createUser('globex@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'payment.write'], 'member');
        $this->login('globex@twes.local', 'password-1234');
        try {
            return $work();
        } finally {
            [$this->company, $this->customerId] = [$acme, $acmeCustomer];
            $this->login('sales@twes.local', 'password-1234');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(mixed $row): array
    {
        self::assertIsArray($row);
        $named = [];
        foreach ($row as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /** An invoice issued, paid in full, and `$amount` more kept to the customer's credit from it. */
    private function overpaid(string $amount): string
    {
        $invoice = $this->issue('10');
        $this->pay($invoice, '10');
        $this->postJson($this->invoicePath($invoice).'/overpayments', ['amount' => $amount, 'date' => $this->today()]);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        return $invoice;
    }

    private function pay(string $invoice, string $amount): void
    {
        $this->postJson($this->invoicePath($invoice).'/payments', ['date' => $this->today(), 'amount' => $amount, 'method' => 'cash', 'reference' => null, 'notes' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    private function issue(string $net): string
    {
        $id = $this->draft($net);
        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
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

    private function customer(string $number, string $name): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, $number, self::aTunisianBusiness($name), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function unitId(): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    private function today(): string
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function creditPath(): string
    {
        return $this->companyPath().'/customers/'.$this->customerId.'/credit-balance';
    }

    private function invoicePath(string $id): string
    {
        return $this->companyPath().'/invoices/'.$id;
    }
}
