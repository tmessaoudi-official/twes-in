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
use App\Module\Invoices\Domain\Invoice;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Tenancy\Domain\Company;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * A document's figures while it is typed (docs/SPEC.md § 7, the live line figures): the API works them out with the
 * calculator that saves them, from the body a save would send, and keeps nothing.
 */
final class DocumentPreviewTest extends ApiTestCase
{
    private const array NOTE_WRITER = ['delivery_note.read', 'delivery_note.write', 'customer.read'];
    private const array WRITER = ['quote.read', 'quote.write', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'customer.read'];

    private Company $company;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $this->customer = Customer::create($this->company, 'CLI-0001', self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($this->customer);
        $this->em()->flush();
    }

    /**
     * 3 × 12.5 less 10 % is 33.750 net; FODEC 1 % on it, then VAT 19 % on the net and the FODEC. The preview says each
     * line's figures and the document's, as a save of the same body then stores them, and saves nothing itself.
     */
    public function testAnInvoiceIsWorkedOutAsItWouldBeSavedAndNothingIsKept(): void
    {
        $this->signedIn(self::WRITER);
        $body = $this->invoiceBody([
            ['description' => 'Écrou', 'quantity' => '3', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '12.5', 'discountRate' => '10', 'taxComponentIds' => [$this->taxId('FODEC'), $this->taxId('TVA19')]],
            ['description' => 'Pose', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '20', 'taxComponentIds' => [$this->taxId('TVA19')]],
        ]);
        $before = $this->invoiceCount();

        $this->postJson($this->companyPath().'/invoices/preview', $body);

        self::assertResponseIsSuccessful();
        $preview = $this->json();
        self::assertSame($before, $this->invoiceCount(), 'a preview creates no invoice');
        $this->assertNothingPending();

        $lines = $this->rows($preview, 'lines');
        self::assertSame(['37.500', '3.750', '33.750', '0.000'], [$lines[0]['amount'], $lines[0]['discount'], $lines[0]['net'], $lines[0]['documentDiscount']]);
        self::assertSame(['FODEC', 'TVA19'], array_column($this->rows($lines[0], 'taxes'), 'code'));
        self::assertSame(['33.750', '34.088'], array_column($this->rows($lines[0], 'taxes'), 'base'), 'VAT is charged on the FODEC too');
        self::assertSame('20.000', $lines[1]['net']);
        self::assertSame('3.750', $preview['savings'], 'what the discounts take off');

        $this->postJson($this->companyPath().'/invoices', $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $saved = $this->json();
        foreach (['subtotalNet', 'documentDiscount', 'savings', 'totalNet', 'taxes', 'totalTax', 'fixedTaxes', 'total', 'withholdings', 'netToPay'] as $key) {
            self::assertSame($saved[$key], $preview[$key], "the preview's $key is what the save stored");
        }
        self::assertSame(array_column($this->rows($saved, 'lines'), 'net'), array_column($lines, 'net'));

        // Each line's taxes are its share of the document's, so they add up to it; its total is what it adds.
        $taxes = '0';
        foreach ($lines as $line) {
            $own = '0';
            foreach ($this->rows($line, 'taxes') as $tax) {
                $own = bcadd($own, $this->numberAt($tax, 'amount'), 3);
            }
            $taxes = bcadd($taxes, $own, 3);
            self::assertSame(bcadd(bcsub($this->numberAt($line, 'net'), $this->numberAt($line, 'documentDiscount'), 3), $own, 3), $line['total']);
        }
        self::assertSame($preview['totalTax'], $taxes);
    }

    /**
     * A draft is previewed as itself: a credit note's figures are negative, and drafting nothing new, it leaves the
     * invoice it corrects as it found it.
     */
    public function testADraftCreditNoteIsPreviewedAsOneAndItsInvoiceIsLeftAlone(): void
    {
        $this->signedIn(self::WRITER);
        $this->postJson($this->companyPath().'/invoices', $this->invoiceBody([
            ['description' => 'Pose', 'quantity' => '2', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '50', 'taxComponentIds' => [$this->taxId('TVA19')]],
        ]));
        $invoiceId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->companyPath().'/invoices/'.$invoiceId.'/issue', []);
        self::assertResponseIsSuccessful();
        $this->postJson($this->companyPath().'/invoices/'.$invoiceId.'/credit-notes', ['creditNoteReason' => 'Retour']);
        $creditId = $this->stringAt($this->json(), 'id');

        $this->postJson($this->companyPath().'/invoices/'.$creditId.'/preview', $this->invoiceBody([
            ['description' => 'Pose', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '50', 'taxComponentIds' => [$this->taxId('TVA19')]],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(['-50.000', '-9.500'], [$this->json()['totalNet'], $this->json()['totalTax']]);
        $this->assertNothingPending();
        $invoice = $this->em()->find(Invoice::class, $invoiceId);
        self::assertNotNull($invoice);
        self::assertCount(1, $invoice->getCorrections(), 'the preview never stood as another correction of the invoice');

        $this->getJson($this->companyPath().'/invoices/'.$creditId);
        self::assertSame('-100.000', $this->json()['totalNet'], 'the draft keeps what was saved');
    }

    public function testWhatASaveWouldRefuseIsRefusedWithItsField(): void
    {
        $this->signedIn(self::WRITER);

        $this->postJson($this->companyPath().'/invoices/preview', $this->invoiceBody([
            ['description' => 'Pose', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '20', 'taxComponentIds' => ['0190a3c5-0000-7000-8000-000000000000']],
        ]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('lines[0].taxComponentIds', $this->stringAt($this->json(), 'detail'));

        $this->postJson($this->companyPath().'/invoices', $this->invoiceBody([
            ['description' => 'Pose', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '20', 'taxComponentIds' => []],
        ]));
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->companyPath().'/invoices/'.$id.'/issue', []);
        $this->postJson($this->companyPath().'/invoices/'.$id.'/preview', $this->invoiceBody([]));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an issued invoice shows the figures it was issued with');
    }

    public function testAPreviewIsForWhoeverMayWriteTheDocument(): void
    {
        $this->signedIn(['invoice.read', 'quote.read', 'customer.read']);

        // A role that does not grant writing answers as a stranger does: the guard says nothing of the company.
        $this->postJson($this->companyPath().'/invoices/preview', $this->invoiceBody([]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('No such company.', $this->json()['detail']);
        $this->postJson($this->companyPath().'/quotes/preview', $this->quoteBody([]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('No such company.', $this->json()['detail']);
    }

    public function testAQuoteIsWorkedOutAsItWouldBeSavedAndNothingIsKept(): void
    {
        $this->signedIn(self::WRITER);
        $body = $this->quoteBody([
            ['description' => 'Écrou', 'quantity' => '3', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '12.5', 'discountRate' => '10', 'taxComponentIds' => [$this->taxId('FODEC'), $this->taxId('TVA19')]],
        ]);

        $this->postJson($this->companyPath().'/quotes/preview', $body);

        self::assertResponseIsSuccessful();
        $preview = $this->json();
        $this->assertNothingPending();
        $this->postJson($this->companyPath().'/quotes', $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $saved = $this->json();
        foreach (['subtotalNet', 'documentDiscount', 'savings', 'taxes', 'totalTax', 'total'] as $key) {
            self::assertSame($saved[$key], $preview[$key], "the preview's $key is what the save stored");
        }
        self::assertSame('33.750', $this->rows($preview, 'lines')[0]['net']);

        $id = $this->stringAt($saved, 'id');
        $this->postJson($this->companyPath().'/quotes/'.$id.'/preview', [...$body, 'discountAmount' => '3.75']);
        self::assertResponseIsSuccessful();
        self::assertSame('3.750', $this->json()['documentDiscount']);
        self::assertSame('7.500', $this->json()['savings'], 'the line discount and the document discount together');
        self::assertSame(['33.750', '3.750'], [$this->rows($this->json(), 'lines')[0]['net'], $this->rows($this->json(), 'lines')[0]['documentDiscount']], 'a line says its share of the document discount');
        self::assertSame($this->json()['total'], $this->rows($this->json(), 'lines')[0]['total'], 'the only line adds what the quote comes to, its share of the discount taken off');
    }

    /**
     * A delivery note's lines as they are typed, by the calculator that saves them: no discount and no document tax, a
     * VAT on the FODEC as on an invoice, and nothing kept. A saved draft is previewed with the body that would revise it.
     */
    public function testADeliveryNoteIsWorkedOutAsItWouldBeSavedAndNothingIsKept(): void
    {
        $this->signedIn(self::NOTE_WRITER);
        $body = $this->noteBody([
            ['productId' => null, 'description' => 'Écrou', 'quantity' => '3', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '12.5', 'taxComponentIds' => [$this->taxId('FODEC'), $this->taxId('TVA19')], 'lotCode' => null],
            ['productId' => null, 'description' => 'Pose', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '20', 'taxComponentIds' => [$this->taxId('TVA19')], 'lotCode' => null],
        ]);

        $this->postJson($this->companyPath().'/delivery-notes/preview', $body);

        self::assertResponseIsSuccessful();
        $preview = $this->json();
        $this->assertNothingPending();
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery_note'), 'a preview creates no note');
        $lines = $this->rows($preview, 'lines');
        self::assertSame(['37.500', '0.000', '37.500', '0.000'], [$lines[0]['amount'], $lines[0]['discount'], $lines[0]['net'], $lines[0]['documentDiscount']]);
        self::assertSame(['37.500', '37.875'], array_column($this->rows($lines[0], 'taxes'), 'base'), 'VAT is charged on the FODEC too');
        self::assertSame('20.000', $lines[1]['net']);

        $this->postJson($this->companyPath().'/delivery-notes', $body);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $saved = $this->json();
        foreach (['subtotalNet', 'totalTax', 'total'] as $key) {
            self::assertSame($saved[$key], $preview[$key], "the preview's $key is what the save stored");
        }
        self::assertSame(array_column($this->rows($saved, 'taxes'), 'amount'), array_column($this->rows($preview, 'taxes'), 'amount'));
        self::assertSame(array_column($this->rows($saved, 'lines'), 'net'), array_column($lines, 'net'));

        $id = $this->stringAt($saved, 'id');
        $this->postJson($this->companyPath().'/delivery-notes/'.$id.'/preview', [...$body, 'lines' => [[...$this->rows($body, 'lines')[1], 'quantity' => '2']]]);
        self::assertResponseIsSuccessful();
        self::assertSame(['40.000', '47.600'], [$this->json()['subtotalNet'], $this->json()['total']]);
        $this->assertNothingPending();
        $this->getJson($this->companyPath().'/delivery-notes/'.$id);
        self::assertSame('57.500', $this->json()['subtotalNet'], 'the draft keeps what was saved');
    }

    /** The screen sends no lot while a note is typed: a product tracked by lot is worked out before its lot is named. */
    public function testADeliveryNoteLineOfAProductTrackedByLotIsWorkedOutBeforeItsLotIsNamed(): void
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $now = new \DateTimeImmutable();
        $drill = Product::create($this->company, 'ART-001', new ProductDetails('Perceuse', null, ProductKind::Goods, '120'), $unit, null, [], $now);
        $drill->track(ProductTracking::Lot, $now);
        $this->em()->persist($drill);
        $this->em()->flush();
        $this->signedIn([...self::NOTE_WRITER, 'product.read']);

        $this->postJson($this->companyPath().'/delivery-notes/preview', $this->noteBody([
            ['productId' => $drill->getId()->toRfc4122(), 'description' => 'Perceuse', 'quantity' => '2', 'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '120', 'taxComponentIds' => [], 'lotCode' => null],
        ]));

        self::assertResponseIsSuccessful();
        self::assertSame(['240.000', '240.000'], [$this->rows($this->json(), 'lines')[0]['net'], $this->json()['total']]);
    }

    public function testADeliveryNoteRefusedOrNoLongerADraftIsNotPreviewed(): void
    {
        $this->signedIn([...self::NOTE_WRITER, 'delivery_note.validate']);
        $line = ['productId' => null, 'description' => 'Pose', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '20', 'taxComponentIds' => [$this->taxId('TVA19')], 'lotCode' => null];

        $this->postJson($this->companyPath().'/delivery-notes/preview', $this->noteBody([[...$line, 'taxComponentIds' => ['0190a3c5-0000-7000-8000-000000000000']]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringStartsWith('lines[0].taxComponentIds', $this->stringAt($this->json(), 'detail'));

        $this->postJson($this->companyPath().'/delivery-notes', $this->noteBody([$line]));
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->companyPath().'/delivery-notes/'.$id.'/validate', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->companyPath().'/delivery-notes/'.$id.'/preview', $this->noteBody([$line]));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a validated note shows the figures it was validated with');

        $this->postJson($this->companyPath().'/delivery-notes/0190a3c5-0000-7000-8000-000000000000/preview', $this->noteBody([$line]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testADeliveryNoteIsPreviewedOnlyByWhoeverMayWriteItInItsOwnCompany(): void
    {
        $this->signedIn(self::NOTE_WRITER);
        $this->postJson($this->companyPath().'/delivery-notes', $this->noteBody([]));
        $id = $this->stringAt($this->json(), 'id');

        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $this->createUser('other@twes.local', 'password-1234', $globex, self::NOTE_WRITER, 'member');
        $this->login('other@twes.local', 'password-1234');
        $this->postJson('/api/companies/'.$globex->getId()->toRfc4122().'/delivery-notes/'.$id.'/preview', $this->noteBody([]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s note');
        $this->postJson($this->companyPath().'/delivery-notes/'.$id.'/preview', $this->noteBody([]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a company one is not a member of');

        // The test client's requests reboot the kernel: the company is the one its entity manager holds now.
        $this->createUser('reader@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()), ['delivery_note.read', 'customer.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');
        $this->postJson($this->companyPath().'/delivery-notes/preview', $this->noteBody([]));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame('No such company.', $this->json()['detail']);
    }

    /** What the request's own unit of work holds: a preview leaves nothing to write, not even a changed field. */
    private function assertNothingPending(): void
    {
        $unitOfWork = static::getContainer()->get(EntityManagerInterface::class)->getUnitOfWork();
        $unitOfWork->computeChangeSets();
        // Named by class: comparing the entities themselves makes PHPUnit diff whole object graphs, which never ends.
        $named = static fn (array $pending): array => array_values(array_map(get_debug_type(...), $pending));
        self::assertSame([], $named($unitOfWork->getScheduledEntityInsertions()), 'nothing to insert');
        self::assertSame([], $named($unitOfWork->getScheduledEntityUpdates()), 'nothing to update');
        self::assertSame([], $named($unitOfWork->getScheduledCollectionUpdates()), 'no collection changed');
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return numeric-string
     */
    private function numberAt(array $row, string $key): string
    {
        $value = $this->stringAt($row, $key);
        if (!is_numeric($value)) {
            self::fail(\sprintf('%s is not a number: %s', $key, $value));
        }

        return $value;
    }

    private function invoiceCount(): int
    {
        return \count($this->em()->getRepository(Invoice::class)->findAll());
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function invoiceBody(array $lines): array
    {
        return ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'documentTaxComponentIds' => [], 'lines' => $lines];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function quoteBody(array $lines): array
    {
        return ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => $lines];
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function noteBody(array $lines): array
    {
        return ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null, 'remarksPrinted' => null, 'notesInternal' => null, 'lines' => $lines];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $body, string $key): array
    {
        $rows = [];
        foreach ($this->arrayAt($body, $key) as $row) {
            self::assertIsArray($row, "$key holds objects");
            $rows[] = array_combine(array_map(strval(...), array_keys($row)), array_values($row));
        }

        return $rows;
    }

    private function unitId(string $code): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit->getId()->toRfc4122();
    }

    private function taxId(string $code): string
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId()->toRfc4122();
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
}
