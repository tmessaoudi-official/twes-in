<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Contact;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\ContactDetails;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Infrastructure\ApiPlatform\Output\RecordChangedProblem;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Customers merged into one stay, switched off, pointing at the customer kept: their drafts, contacts, own price lists
 * and settings move to it, while what was sent or issued and the credit they hold keep naming them, as the documents
 * printed them. « Annuler la fusion » puts everything back, until the customer kept has traded since. A customer
 * nothing names is deleted with its contacts and settings.
 */
final class CustomerMergeTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regimes = static::getContainer()->get(CustomerTaxRegimeRepository::class);
        $standard = $regimes->ofPresetAndCode('TN', 'standard');
        $exempt = $regimes->ofPresetAndCode('TN', 'exempt');
        self::assertNotNull($standard);
        self::assertNotNull($exempt);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $product = Product::create($this->company, 'VIS-6', new ProductDetails('Vis 6', null, ProductKind::Goods, '0.500'), $piece, null, [], $now);
        $this->em()->persist($product);
        $this->productId = $product->getId()->toRfc4122();
        $matricule = ['matricule_fiscal' => '1234567A/B/M/000'];
        $customers = [
            'C-001' => [new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', identifiers: $matricule), $standard],
            'C-002' => [new CustomerProfile(CustomerKind::Company, 'Carthage Conseil Sfax', identifiers: $matricule), $standard],
            'C-003' => [new CustomerProfile(CustomerKind::Company, 'Carthage'), $standard],
            'C-004' => [new CustomerProfile(CustomerKind::Company, 'Visserie du Sud', identifiers: ['matricule_fiscal' => '7654321B/A/M/000']), $standard],
            'C-005' => [new CustomerProfile(CustomerKind::Individual, 'Sonia Ben Ali'), $standard],
            'C-006' => [new CustomerProfile(CustomerKind::Company, 'Carthage Export'), $exempt],
        ];
        foreach ($customers as $number => [$profile, $regime]) {
            $this->em()->persist(Customer::create($this->company, $number, $profile, null, $regime, [], $now));
        }
        $this->em()->flush();
    }

    public function testDraftsContactsPriceListsAndSettingsMoveWhileWhatWasSentAndTheCreditKeepTheCustomerMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->customerId('C-001'), $this->customerId('C-002'), $this->customerId('C-003')];
        $draftInvoice = $this->anInvoice($merged);
        $issued = $this->anInvoice($merged, issued: true);
        $draftQuote = $this->aQuote($merged);
        $sentQuote = $this->aQuote($merged, sent: true);
        $draftNote = $this->aDeliveryNote($merged);
        $validated = $this->aDeliveryNote($merged, validated: true);
        $keptPrimary = $this->aContact($kept, 'Amel', primary: true);
        $mergedPrimary = $this->aContact($merged, 'Karim', primary: true);
        $priceList = $this->aPriceList($merged);
        $credit = $this->aCreditEntry($merged);
        $this->aSetting($kept, 'credit.limit', '500');
        $this->aSetting($merged, 'credit.limit', '900');
        $this->aSetting($merged, 'document.payment_terms_days', 45);

        $merge = $this->merged($kept, [$merged, $other]);

        self::assertSame(['merge', ['id' => $kept, 'label' => 'C-001 Carthage Conseil']], [$merge['kind'], $merge['kept']]);
        $connection = $this->em()->getConnection();
        foreach ([$merged, $other] as $id) {
            self::assertSame(['merged_into_id' => $kept, 'is_active' => false], $connection->fetchAssociative('SELECT merged_into_id::text AS merged_into_id, is_active FROM customer WHERE id = ?', [$id]));
        }
        self::assertSame([$kept, $merged], [$this->customerOf('invoice', $draftInvoice), $this->customerOf('invoice', $issued)]);
        self::assertSame([$kept, $merged], [$this->customerOf('quote', $draftQuote), $this->customerOf('quote', $sentQuote)]);
        self::assertSame([$kept, $merged], [$this->customerOf('delivery_note', $draftNote), $this->customerOf('delivery_note', $validated)]);
        self::assertSame([$kept, $kept], [$this->customerOf('contact', $keptPrimary), $this->customerOf('contact', $mergedPrimary)]);
        // The customer kept has one first contact: the one that came with the merge is one of its contacts.
        self::assertSame([true, false], [$connection->fetchOne('SELECT is_primary FROM contact WHERE id = ?', [$keptPrimary]), $connection->fetchOne('SELECT is_primary FROM contact WHERE id = ?', [$mergedPrimary])]);
        self::assertSame($kept, $this->customerOf('price_list', $priceList));
        self::assertSame($merged, $this->customerOf('customer_credit_entry', $credit));
        // The customer kept keeps its own credit limit, and takes the payment terms it had none of.
        self::assertSame([['credit.limit', '500'], ['document.payment_terms_days', '45']], $this->settingsOf($kept));
        self::assertSame([], $this->settingsOf($merged));
        self::assertEqualsCanonicalizing([$merged, $other], $connection->fetchFirstColumn("SELECT entity_id::text FROM audit_log WHERE action = 'customer.merged'"));
    }

    public function testUndoingTheMergeSwitchesTheCustomersBackOnAndPointsEveryLinkBack(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];
        $this->aContact($kept, 'Amel', primary: true);
        $this->aContact($merged, 'Karim', primary: true);
        $this->aContact($merged, 'Nour', primary: false);
        $this->aPriceList($merged);
        $this->aSetting($kept, 'credit.limit', '500');
        $this->aSetting($merged, 'credit.limit', '900');
        $draft = $this->anInvoice($merged);
        $before = $this->state();

        $merge = $this->merged($kept, [$merged]);
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->state());
        self::assertSame($merged, $this->customerOf('invoice', $draft));
        self::assertSame(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'customer.merge_undone' AND entity_id = ?", [$merged]));
    }

    /** Once the customer kept was invoiced since, the merge is part of what happened: it is no longer undone. */
    public function testTheMergeIsNotUndoneOnceTheCustomerKeptWasInvoicedSince(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->anInvoice($kept, issued: true);

        $this->postJson($this->path("record-removals/$merge/restore"), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_transacted', 'uses' => [['table' => 'invoice', 'count' => 1]]], $this->json());
        self::assertSame($kept, $this->em()->getConnection()->fetchOne('SELECT merged_into_id::text FROM customer WHERE id = ?', [$merged]));
    }

    /** A draft the merge moved and that was sent since prints the customer kept: the merge is no longer undone. */
    public function testTheMergeIsNotUndoneOnceADraftItMovedWasSent(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];
        $quote = $this->aQuote($merged);
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->em()->getConnection()->executeStatement("UPDATE quote SET status = 'sent', updated_at = now() + interval '1 second' WHERE id = ?", [$quote]);

        $this->postJson($this->path("record-removals/$merge/restore"), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_transacted', 'uses' => [['table' => 'quote', 'count' => 1]]], $this->json());
    }

    /** Credit the customer kept was given or used since is money moved under the merge: it is no longer undone. */
    public function testTheMergeIsNotUndoneOnceTheCustomerKeptsCreditMovedSince(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];
        $merge = $this->stringAt($this->merged($kept, [$merged]), 'id');
        $this->aCreditEntry($kept, later: true);

        $this->postJson($this->path("record-removals/$merge/restore"), null);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(['error' => 'merge_transacted', 'uses' => [['table' => 'customer_credit_entry', 'count' => 1]]], $this->json());
    }

    /**
     * Two customers registered under different numbers, taxed under different regimes, or one a company and the other a
     * person, are not one customer, whatever their names say.
     */
    public function testCustomersOfDifferentNumbersRegimesOrKindsAreNotMerged(): void
    {
        $this->signedIn();
        $kept = $this->customerId('C-001');

        foreach (['C-004' => 'identifiers', 'C-006' => 'tax_regime_id', 'C-005' => 'kind'] as $number => $column) {
            $this->postJson($this->path('record-merges'), ['subject' => 'customer', 'keptId' => $kept, 'ids' => [$this->customerId($number)]]);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
            self::assertSame(['error' => 'merge_mismatch', 'column' => $column], $this->json(), $number);
        }
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM customer WHERE merged_into_id IS NOT NULL'));
        // The same number, or none on one side, is no mismatch.
        $this->merged($kept, [$this->customerId('C-002'), $this->customerId('C-003')]);
    }

    /** A customer nothing names goes with its contacts and its settings, and comes back with them. */
    public function testACustomerNothingNamesIsDeletedWithItsContactsAndSettings(): void
    {
        $this->signedIn();
        $customer = $this->customerId('C-003');
        $contact = $this->aContact($customer, 'Karim', primary: true);
        $this->aSetting($customer, 'credit.limit', '900');
        $before = $this->state();

        $this->postJson($this->path('record-removals'), ['subject' => 'customer', 'ids' => [$customer]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $connection = $this->em()->getConnection();
        self::assertSame([0, 0], [$connection->fetchOne('SELECT count(*) FROM customer WHERE id = ?', [$customer]), $connection->fetchOne('SELECT count(*) FROM contact WHERE id = ?', [$contact])]);
        self::assertSame([], $this->settingsOf($customer));
        $this->postJson($this->path('record-removals/'.$this->stringAt($this->json(), 'id').'/restore'), null);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->state());
    }

    /** A customer a document, a price list or a credit names is kept: deleting it would leave them naming nothing. */
    public function testACustomerSomethingNamesIsNotDeleted(): void
    {
        $this->signedIn();
        [$invoiced, $listed, $credited] = [$this->customerId('C-001'), $this->customerId('C-002'), $this->customerId('C-003')];
        $this->anInvoice($invoiced);
        $this->aPriceList($listed);
        $this->aCreditEntry($credited);

        foreach ([$invoiced => 'invoice', $listed => 'price_list', $credited => 'customer_credit_entry'] as $id => $table) {
            $this->postJson($this->path('record-removals'), ['subject' => 'customer', 'ids' => [$id]]);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
            self::assertSame('record_used', $this->json()['error'] ?? null, $table);
            self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM customer WHERE id = ?', [$id]));
        }
    }

    /**
     * A customer merged away leaves the list, says which customer it stands for, and is no longer revised, from a screen
     * or a file, as what it was stays as it was merged.
     */
    public function testACustomerMergedAwayIsListedNoMoreAndNotRevised(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];
        $this->merged($kept, [$merged]);

        $this->getJson($this->path('customers'));
        self::assertSame(['C-001', 'C-003', 'C-004', 'C-005', 'C-006'], array_column($this->jsonList(), 'number'));
        $this->getJson($this->path('customers?isActive=false'));
        self::assertSame([], $this->jsonList());
        $this->getJson($this->path("customers/$merged"));
        self::assertSame(['id' => $kept, 'number' => 'C-001', 'name' => 'Carthage Conseil'], $this->json()['mergedInto'] ?? null);
        $this->getJson($this->path("customers/$kept"));
        self::assertNull($this->json()['mergedInto'] ?? null);

        $this->sendJson('PUT', $this->path("customers/$merged"), ['number' => 'C-002', 'kind' => 'company', 'name' => 'Carthage Conseil Sfax II', 'isActive' => true, 'version' => $this->storedVersion('customer', $merged)]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame(RecordChangedProblem::TYPE, $this->json()['type'] ?? null);

        $this->uploadFile($this->path('imports/customers'), 'customers.csv', "number,name,kind\nC-002,Carthage Conseil Sfax II,company\n", 'file', ['mode' => 'upsert', 'dryRun' => '0']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $rejected = $this->arrayAt($this->json(), 'rejected');
        self::assertIsArray($rejected[0] ?? null);
        self::assertSame([2, 'number', 'customer_merged', ['number' => 'C-002', 'kept' => 'C-001']], [$rejected[0]['line'] ?? null, $rejected[0]['column'] ?? null, $rejected[0]['code'] ?? null, $rejected[0]['params'] ?? null]);
        self::assertSame(['Carthage Conseil Sfax', false], [$this->em()->getConnection()->fetchOne('SELECT name FROM customer WHERE id = ?', [$merged]), $this->em()->getConnection()->fetchOne('SELECT is_active FROM customer WHERE id = ?', [$merged])]);
    }

    /**
     * What was issued to a customer merged away is read under the customer kept, in every customer report and in
     * « À surveiller », while the invoice itself keeps naming the customer it was issued to.
     */
    public function testTheReportsAndTheWatchReadTheCustomerKeptWithWhatWasMergedIntoIt(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->customerId('C-001'), $this->customerId('C-002'), $this->customerId('C-003')];
        $issued = [$this->anIssuedLateInvoice($kept), $this->anIssuedLateInvoice($merged), $this->anIssuedLateInvoice($other)];
        $this->merged($kept, [$merged]);
        $today = $this->em()->getConnection()->fetchOne('SELECT CURRENT_DATE::text');
        self::assertIsString($today);

        $expected = [[$kept, 'Carthage Conseil', 2], [$other, 'Carthage', 1]];
        foreach (['sales-by-customer' => 'documents', 'aged-balance' => 'invoices', 'credit-notes-and-discounts' => 'discounted_invoices', 'withholding-suffered' => 'documents'] as $report => $count) {
            // The aged balance reads today and takes no period.
            $period = 'aged-balance' === $report ? '' : "from=$today&to=$today";
            $this->getJson($this->path("reports/$report?$period"));
            self::assertResponseIsSuccessful();
            $rows = [];
            foreach ($this->arrayAt($this->json(), 'rows') as $row) {
                self::assertIsArray($row);
                $cells = \is_array($row['cells'] ?? null) ? $row['cells'] : [];
                $rows[] = [$row['key'] ?? null, $cells['customer'] ?? null, $cells[$count] ?? null];
            }
            usort($rows, static fn (array $a, array $b): int => $b[2] <=> $a[2]);
            self::assertSame($expected, $rows, "$report reads the customer kept");

            $this->getJson($this->path("reports/$report/documents?$period&row=$kept"));
            self::assertResponseIsSuccessful();
            $documents = array_column($this->arrayAt($this->json(), 'documents'), 'id');
            sort($documents);
            $both = [$issued[0], $issued[1]];
            sort($both);
            self::assertSame($both, $documents, "$report opens what was issued to both");
        }

        $this->getJson($this->path('watch/invoices.late_customer'));
        self::assertResponseIsSuccessful();
        $late = array_map(static fn (array $row): array => [$row['subjectId'] ?? null, \is_array($row['params'] ?? null) ? [$row['params']['customer'] ?? null, $row['params']['invoices'] ?? null] : null], $this->jsonList());
        // Due the same day, the two customers late read by name.
        self::assertSame([[$other, ['Carthage', 1]], [$kept, ['Carthage Conseil', 2]]], $late);
        $this->getJson($this->path('watch'));
        self::assertResponseIsSuccessful();
        $counts = array_column($this->arrayAt($this->json(), 'subjects'), 'count', 'kind');
        self::assertSame(2, $counts['invoices.late_customer'] ?? null, 'two customers late, not three');
        self::assertSame($merged, $this->em()->getConnection()->fetchOne('SELECT customer_id::text FROM invoice WHERE id = ?', [$issued[1]]), 'the invoice keeps the customer it was issued to');

        // A cheque handed for an invoice of the customer merged away falls due as the customer kept's.
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO payment_instrument (id, invoice_id, company_id, kind, amount, due_on, bank, number, status, created_at, updated_at)
             VALUES (?, ?, ?, 'check', '1.000', CURRENT_DATE, '', 'CHQ-1', 'held', now(), now())",
            [Uuid::v7()->toRfc4122(), $issued[1], $this->companyId()],
        );
        $this->getJson($this->path('watch/invoices.instruments_due'));
        self::assertResponseIsSuccessful();
        self::assertSame([[$issued[1], 'Carthage Conseil']], array_map(static fn (array $row): array => [$row['subjectId'] ?? null, \is_array($row['params'] ?? null) ? $row['params']['customer'] ?? null : null], $this->jsonList()));

        // What was paid beyond an invoice of the customer merged away is taken from the customer kept.
        $this->em()->getConnection()->executeStatement('UPDATE customer_credit_entry SET invoice_id = ? WHERE id = ?', [$issued[1], $this->aCreditEntry($merged)]);
        $this->getJson($this->path("reports/takings/documents?from=$today&to=$today&row=d0"));
        self::assertResponseIsSuccessful();
        self::assertSame([[$issued[1], 'Carthage Conseil']], array_map(static fn (mixed $document): array => \is_array($document) ? [$document['id'] ?? null, $document['party'] ?? null] : [], $this->arrayAt($this->json(), 'documents')));
    }

    /**
     * The lists narrowed to the customer kept show what stays with the customer merged away too, named and sorted as the
     * customer kept, while each document still names the customer it was made out to.
     */
    public function testTheListsNarrowedToTheCustomerKeptShowWhatStayedWithTheOneMergedAway(): void
    {
        $this->signedIn();
        [$kept, $merged, $other] = [$this->customerId('C-001'), $this->customerId('C-002'), $this->customerId('C-003')];
        // A name sorting before every other, so a list sorted by the name recorded would put it first.
        $this->em()->getConnection()->executeStatement("UPDATE customer SET name = 'Abidi' WHERE id = ?", [$merged]);
        $invoices = [$this->anIssuedLateInvoice($kept), $this->anIssuedLateInvoice($merged), $this->anIssuedLateInvoice($other)];
        $quote = $this->aQuote($merged, sent: true);
        $note = $this->aDeliveryNote($merged, validated: true);
        [$otherQuote, $otherNote] = [$this->aQuote($other, sent: true), $this->aDeliveryNote($other, validated: true)];
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO payment_instrument (id, invoice_id, company_id, kind, amount, due_on, bank, number, status, created_at, updated_at)
             VALUES (?, ?, ?, 'check', '1.000', CURRENT_DATE, '', 'CHQ-1', 'held', now(), now())",
            [Uuid::v7()->toRfc4122(), $invoices[1], $this->companyId()],
        );
        $this->merged($kept, [$merged]);

        $this->getJson($this->path('invoices?customerId='.$kept.'&order[number]=asc'));
        $rows = array_map(static fn (array $row): array => [$row['id'] ?? null, $row['customerId'] ?? null, $row['customerName'] ?? null], $this->jsonList());
        $expected = [[$invoices[0], $kept, 'Carthage Conseil'], [$invoices[1], $merged, 'Carthage Conseil']];
        usort($rows, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        usort($expected, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        self::assertSame($expected, $rows, 'invoices');
        $this->getJson($this->path('quotes?customerId='.$kept));
        self::assertSame([[$quote, $merged, 'Carthage Conseil']], array_map(static fn (array $row): array => [$row['id'] ?? null, $row['customerId'] ?? null, $row['customerName'] ?? null], $this->jsonList()), 'quotes');
        $this->getJson($this->path('delivery-notes?customerId='.$kept));
        self::assertSame([[$note, $merged, 'Carthage Conseil']], array_map(static fn (array $row): array => [$row['id'] ?? null, $row['customerId'] ?? null, $row['customerName'] ?? null], $this->jsonList()), 'delivery notes');
        $this->getJson($this->path('instruments?customerId='.$kept));
        self::assertSame(['Carthage Conseil'], array_column($this->jsonList(), 'customerName'), 'instruments');

        // Sorted by the name shown: « Carthage » before « Carthage Conseil », where « Abidi », the name recorded, would come first.
        foreach (['invoices' => $invoices[2], 'quotes' => $otherQuote, 'delivery-notes' => $otherNote] as $list => $first) {
            $this->getJson($this->path("$list?order[customer]=asc"));
            self::assertSame($first, array_column($this->jsonList(), 'id')[0] ?? null, $list);
        }

        // The customer's account counts what is overdue on the invoices of the customer merged into it.
        $this->getJson($this->path("customers/$kept/account"));
        self::assertResponseIsSuccessful();
        self::assertSame(2, $this->json()['overdueCount'] ?? null);
    }

    /**
     * The customer kept answers for what the customer merged into it owes and holds: its account, its credit balance and
     * its statement take in both, asked by either id, so a merge never splits what one customer owes in two.
     */
    public function testTheAccountOfTheCustomerKeptTakesInWhatWasMergedIntoIt(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];
        // Each invoice comes to 12 less the 1 the customer withholds; each customer holds 25 paid beyond an invoice, and a
        // credit note gave back 5 of what was paid on the invoice of the customer merged away.
        $this->anIssuedLateInvoice($kept);
        $owedByMerged = $this->anIssuedLateInvoice($merged);
        $this->aCreditEntry($kept);
        $this->aCreditEntry($merged);
        $this->em()->getConnection()->executeStatement("UPDATE customer_credit_entry SET kind = 'credited', amount = '5.000', invoice_id = ? WHERE id = ?", [$owedByMerged, $this->aCreditEntry($merged)]);
        $note = $this->aDeliveryNote($merged, validated: true);
        $this->merged($kept, [$merged]);
        $this->aSetting($kept, 'credit.limit', '20');

        // A delivery to the customer merged away weighs what the customer kept owes, 27 less the 55 it holds, against
        // the limit of the customer kept.
        $this->getJson($this->path("delivery-notes/$note/credit"));
        self::assertResponseIsSuccessful();
        self::assertSame(['20.000', '-28.000'], [$this->json()['limit'] ?? null, $this->json()['owed'] ?? null], 'the delivery\'s credit check');

        foreach ([$kept, $merged] as $asked) {
            $this->getJson($this->path("customers/$asked/credit-balance"));
            self::assertResponseIsSuccessful();
            self::assertSame('55.000', $this->json()['balance'] ?? null, 'the credit balance');
            $this->getJson($this->path("customers/$asked/account"));
            self::assertResponseIsSuccessful();
            $account = $this->json();
            self::assertSame(['27.000', '55.000', 2], [$account['balance'] ?? null, $account['onAccount'] ?? null, $account['overdueCount'] ?? null], 'the account');
            $this->getJson($this->path("customers/$asked/statement"));
            self::assertResponseIsSuccessful();
            $statement = $this->json();
            self::assertSame(['Carthage Conseil', '27.000'], [$statement['customerName'] ?? null, $statement['closingBalance'] ?? null], 'the statement');
        }
    }

    /** Credit held by the customer kept pays an invoice made out to the customer merged into it, and the reverse. */
    public function testTheCreditOfTheCustomerKeptPaysWhatTheOneMergedAwayOwes(): void
    {
        $this->signedIn();
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];
        $owedByMerged = $this->anIssuedLateInvoice($merged);
        $owedByKept = $this->anIssuedLateInvoice($kept);
        $this->aCreditEntry($kept);
        $this->aCreditEntry($merged);
        $this->merged($kept, [$merged]);

        $this->postJson($this->path("invoices/$owedByMerged/apply-credit"), ['amount' => '11']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->postJson($this->path("invoices/$owedByKept/apply-credit"), ['amount' => '11']);
        self::assertResponseIsSuccessful((string) $this->client->getResponse()->getContent());
        $this->getJson($this->path("customers/$kept/credit-balance"));
        self::assertSame('28.000', $this->json()['balance'] ?? null, '50 held, 22 applied');
    }

    /** Before anything runs, the screen says what each customer would hand over and what would stay with it. */
    public function testThePreviewSaysWhatMovesAndWhatStays(): void
    {
        $this->signedIn();
        $merged = $this->customerId('C-002');
        $this->anInvoice($merged);
        $this->anInvoice($merged, issued: true);
        $this->aContact($merged, 'Karim', primary: true);
        $this->aPriceList($merged);
        $this->aCreditEntry($merged);

        $this->getJson($this->path('record-merges/preview?subject=customer&ids[]='.$merged));

        self::assertResponseIsSuccessful();
        self::assertSame([[
            'id' => $merged,
            'label' => 'C-002 Carthage Conseil Sfax',
            'moves' => [['table' => 'contact', 'count' => 1], ['table' => 'invoice', 'count' => 1], ['table' => 'price_list', 'count' => 1]],
            'stays' => [['table' => 'customer_credit_entry', 'count' => 1], ['table' => 'invoice', 'count' => 1]],
        ]], $this->json());
    }

    public function testMergingCustomersTakesWritingThemAndAProofGivenAgain(): void
    {
        $this->signedIn(prove: false);
        [$kept, $merged] = [$this->customerId('C-001'), $this->customerId('C-002')];

        $this->postJson($this->path('record-merges'), ['subject' => 'customer', 'keptId' => $kept, 'ids' => [$merged]]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);

        $this->createUser('reader@twes.local', self::PASSWORD, $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'customer.read'], 'reader');
        $this->login('reader@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('record-merges'), ['subject' => 'customer', 'keptId' => $kept, 'ids' => [$merged]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM customer WHERE merged_into_id IS NOT NULL'));
    }

    /** @return array<string, mixed> */
    private function state(): array
    {
        $connection = $this->em()->getConnection();

        return [
            'customers' => $connection->fetchAllAssociative('SELECT id::text, number, merged_into_id::text, is_active FROM customer WHERE company_id = ? ORDER BY number', [$this->companyId()]),
            'contacts' => $connection->fetchAllAssociative('SELECT id::text, customer_id::text, is_primary, last_name FROM contact WHERE company_id = ? ORDER BY id', [$this->companyId()]),
            'price_lists' => $connection->fetchAllAssociative('SELECT id::text, customer_id::text FROM price_list WHERE company_id = ? ORDER BY id', [$this->companyId()]),
            'settings' => $connection->fetchAllAssociative("SELECT level_id, key, value::text FROM setting WHERE company_id = ? AND level = 'customer' ORDER BY level_id, key", [$this->companyId()]),
        ];
    }

    /** @return list<array{0: string, 1: string}> key and value of each setting kept at the customer's level */
    private function settingsOf(string $customer): array
    {
        return array_map(
            static fn (array $row): array => [\is_string($row['key']) ? $row['key'] : '', trim(\is_string($row['value']) ? $row['value'] : '', '"')],
            $this->em()->getConnection()->fetchAllAssociative("SELECT key, value::text AS value FROM setting WHERE level = 'customer' AND level_id = ? ORDER BY key", [$this->companyId().'/'.$customer]),
        );
    }

    /**
     * @param list<string> $merged
     *
     * @return array<string, mixed>
     */
    private function merged(string $kept, array $merged): array
    {
        $this->postJson($this->path('record-merges'), ['subject' => 'customer', 'keptId' => $kept, 'ids' => $merged]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->json();
    }

    private function anInvoice(string $customer, bool $issued = false): string
    {
        $this->postJson($this->path('invoices'), ['customerId' => $customer, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '2']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($issued) {
            // The engine reads the document's state, not how it got there: issuing for real is the invoices' own tests.
            $this->em()->getConnection()->executeStatement("UPDATE invoice SET status = 'issued', issued_at = now(), updated_at = now() + interval '1 second' WHERE id = ?", [$id]);
        }

        return $id;
    }

    /** An invoice issued today, due forty days ago and unpaid, with a discount and a withholding: in every customer report. */
    private function anIssuedLateInvoice(string $customer): string
    {
        $id = $this->anInvoice($customer, issued: true);
        $this->em()->getConnection()->executeStatement(
            'UPDATE invoice SET number = ?, issue_date = current_date, due_date = current_date - 40, total_net = 12, total_gross = 12, amount_due = 12, savings = 1.000, withholding_amount = 1.000 WHERE id = ?',
            ['FAC-'.substr($id, -12), $id],
        );

        return $id;
    }

    private function aQuote(string $customer, bool $sent = false): string
    {
        $this->postJson($this->path('quotes'), ['customerId' => $customer, 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '3']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($sent) {
            $this->em()->getConnection()->executeStatement("UPDATE quote SET status = 'sent', updated_at = now() - interval '1 minute' WHERE id = ?", [$id]);
        }

        return $id;
    }

    private function aDeliveryNote(string $customer, bool $validated = false): string
    {
        $this->postJson($this->path('delivery-notes'), ['customerId' => $customer, 'establishmentId' => null, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null, 'remarksPrinted' => null, 'notesInternal' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        if ($validated) {
            $this->em()->getConnection()->executeStatement("UPDATE delivery_note SET status = 'validated', updated_at = now() - interval '1 minute' WHERE id = ?", [$id]);
        }

        return $id;
    }

    private function aContact(string $customer, string $lastName, bool $primary): string
    {
        $owner = $this->em()->find(Customer::class, Uuid::fromString($customer));
        self::assertNotNull($owner);
        $contact = Contact::create($owner, new ContactDetails(null, $lastName, null, null, null), $primary, new \DateTimeImmutable());
        $this->em()->persist($contact);
        $this->em()->flush();

        return $contact->getId()->toRfc4122();
    }

    private function aPriceList(string $customer): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->insert('price_list', ['id' => $id, 'company_id' => $this->companyId(), 'name' => 'Tarif '.$id, 'customer_id' => $customer, 'is_active' => 'true', 'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00']);

        return $id;
    }

    /** Money the customer has to their credit, written a minute before the merge, or a minute after it. */
    private function aCreditEntry(string $customer, bool $later = false): string
    {
        $id = Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->executeStatement(
            "INSERT INTO customer_credit_entry (id, company_id, customer_id, entry_date, kind, amount, created_at) VALUES (?, ?, ?, CURRENT_DATE, 'overpayment', '25.000', now() + CAST(? AS interval))",
            [$id, $this->companyId(), $customer, $later ? '1 minute' : '-1 minute'],
        );

        return $id;
    }

    private function aSetting(string $customer, string $key, mixed $value): void
    {
        // A request in between rebooted the kernel: the company is read again from this entity manager.
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? $this->company;
        $this->em()->persist(new Setting(SettingAddress::customer($company, Uuid::fromString($customer)), $key, $value, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    private function customerOf(string $table, string $id): mixed
    {
        return $this->em()->getConnection()->fetchOne(\sprintf('SELECT customer_id::text FROM %s WHERE id = ?', $table), [$id]);
    }

    private function customerId(string $number): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id::text FROM customer WHERE company_id = ? AND number = ?', [$this->companyId(), $number]);
        self::assertIsString($id);

        return $id;
    }

    private function signedIn(bool $prove = true): void
    {
        $permissions = ['company.read', 'customer.read', 'customer.write', 'invoice.read', 'invoice.write', 'payment.write', 'quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write', 'product.read'];
        $this->createUser('shop@twes.local', self::PASSWORD, $this->company, $permissions, 'member');
        $this->login('shop@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        if ($prove) {
            $this->stepUp(self::PASSWORD);
        }
    }

    private function companyId(): string
    {
        return $this->company->getId()->toRfc4122();
    }

    private function path(string $rest): string
    {
        return '/api/companies/'.$this->companyId().'/'.$rest;
    }
}
