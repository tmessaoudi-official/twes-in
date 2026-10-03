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
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A paid invoice is credited up to what it invoiced less its earlier credit notes (docs/SPEC.md § 7): the part of the
 * credit note beyond what is still due was paid, so it goes, at the issuer's choice, to the customer's credit balance
 * or to a refund, and either way leaves its trace in the customer's credit entries.
 */
final class CreditNoteOnPaidInvoiceTest extends ApiTestCase
{
    private Company $company;
    private string $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->createUser('sales@twes.local', 'password-1234', $this->company, ['customer.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write'], 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    public function testTheExcessMustSayWhereItGoesAndTheRefusalChangesNothing(): void
    {
        $invoice = $this->paid('1000', '1000');
        $credit = $this->draftCreditNote($invoice);

        $this->postJson($this->path($credit).'/issue', null);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('excessTo', (string) $this->client->getResponse()->getContent());
        $this->getJson($this->path($invoice));
        self::assertSame(['paid', '0.000'], [$this->json()['status'], $this->json()['amountCredited']]);
        $this->getJson($this->path($credit));
        self::assertSame('draft', $this->json()['status'], 'the refused issue gave its number back');
        $this->getJson($this->creditPath());
        self::assertSame(['0.000', []], [$this->json()['balance'], $this->json()['entries']]);

        $this->postJson($this->path($credit).'/issue?excessTo=elsewhere', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('excessTo', (string) $this->client->getResponse()->getContent());
    }

    public function testTheExcessGoesToTheCreditBalanceWhichTheNextInvoiceCanTake(): void
    {
        $invoice = $this->paid('1000', '1000');
        $credit = $this->draftCreditNote($invoice);

        $this->postJson($this->path($credit).'/issue?excessTo=balance', null);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->getJson($this->path($invoice));
        self::assertSame(['paid', '1000.000', '1000.000', '0.000'], [$this->json()['status'], $this->json()['amountPaid'], $this->json()['amountCredited'], $this->json()['amountDue']], 'what is due never goes below nothing');
        $this->getJson($this->creditPath());
        self::assertSame('1000.000', $this->json()['balance']);
        $entries = $this->arrayAt($this->json(), 'entries');
        self::assertSame(['credited'], array_column($entries, 'kind'));
        self::assertSame([$invoice], array_column($entries, 'invoiceId'));

        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/customers/'.$this->customerId.'/statement');
        self::assertSame(['invoice', 'credit_note', 'payment', 'credit_transfer'], array_column($this->arrayAt($this->json(), 'lines'), 'kind'));
        self::assertSame(['0.000', '1000.000'], [$this->json()['closingBalance'], $this->json()['creditBalance']], 'the account is back to what the invoices have due, the credit apart from it');

        $next = $this->issue('400');
        $this->postJson($this->path($next).'/apply-credit', []);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('paid', $this->json()['status']);
        $this->getJson($this->creditPath());
        self::assertSame('600.000', $this->json()['balance']);
    }

    public function testARefundLeavesTheBalanceAloneAndStillSaysWhereTheMoneyWent(): void
    {
        $invoice = $this->paid('1000', '600');
        $credit = $this->draftCreditNote($invoice);

        $this->postJson($this->path($credit).'/issue?excessTo=refund', null);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->getJson($this->path($invoice));
        self::assertSame(['paid', '600.000', '1000.000', '0.000'], [$this->json()['status'], $this->json()['amountPaid'], $this->json()['amountCredited'], $this->json()['amountDue']], 'the 400 still due is credited, the 600 paid is refunded');
        $this->getJson($this->creditPath());
        self::assertSame('0.000', $this->json()['balance']);
        $entries = $this->arrayAt($this->json(), 'entries');
        self::assertEqualsCanonicalizing(['credited', 'refunded'], array_column($entries, 'kind'));
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.credited'");
        self::assertIsString($changes);
        $audited = json_decode($changes, true);
        self::assertIsArray($audited);
        self::assertSame(['600.000', 'refund'], [$audited['excess'] ?? null, $audited['excessTo'] ?? null]);
    }

    public function testWhatTheInvoiceStillHasDueTakesTheWholeCreditAndNeedsNoDestination(): void
    {
        $invoice = $this->paid('1000', '0');
        $credit = $this->draftCreditNote($invoice);

        $this->postJson($this->path($credit).'/issue', null);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->getJson($this->creditPath());
        self::assertSame(['0.000', []], [$this->json()['balance'], $this->json()['entries']]);
    }

    public function testAPaymentIsKeptOnceAnInvoiceHasGivenMoneyBackSoThatCreditIsNeverCountedTwice(): void
    {
        foreach (['balance', 'refund'] as $destination) {
            $invoice = $this->paid('1000', '1000');
            $credit = $this->draftCreditNote($invoice);
            $this->postJson($this->path($credit).'/issue?excessTo='.$destination, null);
            self::assertResponseStatusCodeSame(Response::HTTP_OK);
            $this->getJson($this->path($invoice));
            $payment = $this->stringAt($this->rowOf($this->arrayAt($this->json(), 'payments')[0]), 'id');

            $this->sendJson('DELETE', $this->path($invoice).'/payments/'.$payment, null);

            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $destination);
            $this->getJson($this->path($invoice));
            self::assertSame(['paid', '1000.000'], [$this->json()['status'], $this->json()['amountPaid']], 'the refused deletion changed nothing');
        }
    }

    /** @return array<string, mixed> */
    private function rowOf(mixed $row): array
    {
        self::assertIsArray($row);
        $named = [];
        foreach ($row as $key => $value) {
            $named[(string) $key] = $value;
        }

        return $named;
    }

    /** An issued invoice of $net with $paid of it paid, when above zero; its id. */
    private function paid(string $net, string $paid): string
    {
        $id = $this->issue($net);
        if ('0' !== $paid) {
            $this->postJson($this->path($id).'/payments', ['date' => $this->today(), 'amount' => $paid, 'method' => 'transfer']);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        }

        return $id;
    }

    private function draftCreditNote(string $invoice): string
    {
        $this->postJson($this->path($invoice).'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function issue(string $net): string
    {
        $this->postJson($this->path(), [
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
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
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

    private function path(?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/invoices'.(null === $id ? '' : '/'.$id);
    }

    private function creditPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/customers/'.$this->customerId.'/credit-balance';
    }
}
