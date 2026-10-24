<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\Model\Unit;
use App\Fiscal\Domain\Repository\CustomerTaxRegimeRepository;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use App\Tests\Support\FakePdfRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * An issued invoice billed its goods and took them out of stock: they leave afterwards with notes on as many days as it
 * takes, each line no more than its credit notes and its other notes leave of it. Such a note moves no stock when it is
 * validated, gives nothing back when it is cancelled, and is never invoiced again.
 */
final class InvoiceDeliveriesTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';
    private const array SELLER = [
        'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'delivery_note.read', 'delivery_note.write',
        'delivery_note.validate', 'stock.read', 'stock.write',
    ];

    private Company $company;
    private Customer $customer;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customer = $this->customer($this->company, 'CLI-0001');
        $now = new \DateTimeImmutable();
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Tour CNC', null, ProductKind::Goods, '1250'), $this->unit('C62'), null, [$this->tax('FODEC')->getId(), $this->tax('TVA19')->getId()], $now);
        $this->em()->persist($product);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testAnIssuedInvoiceIsDeliveredInPartsAndItsGoodsLeaveStockOnce(): void
    {
        $this->signedIn(self::SELLER);
        $this->received('10');
        $invoiceId = $this->issued();
        self::assertSame('4', $this->onHand(), 'the invoice took its six out when it was issued');
        $lines = $this->invoiceLines($invoiceId);
        self::assertCount(2, $lines);
        $lathe = $this->stringAt($lines[0], 'id');

        $this->postJson($this->deliver($invoiceId), ['quantities' => [$lathe => '2']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = $this->json();
        self::assertSame('draft', $first['status']);
        self::assertSame([[$lathe, null, '2.000', '1250.0000', 'Tour CNC']], array_map(static fn (array $line): array => [$line['sourceInvoiceLineId'], $line['sourceQuoteLineId'], $line['quantity'], $line['unitPriceNet'], $line['description']], $this->listOf($first, 'lines')));
        self::assertSame(['invoiceId' => $invoiceId, 'number' => $this->invoiceNumber($invoiceId)], $first['invoice'], 'the note links to the invoice it delivers');
        self::assertSame('CMD-7', $first['customerReference']);

        $pose = $this->stringAt($lines[1], 'id');
        $this->postJson($this->deliver($invoiceId), ['quantities' => [$pose => '1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a service line has no goods to deliver');
        $this->postJson($this->deliver($invoiceId), ['quantities' => [$lathe => '4.001']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('quantities: Only 4.000', $this->detail());
        $this->postJson($this->deliver($invoiceId), ['quantities' => [$lathe => '0']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $firstId = $this->stringAt($first, 'id');
        $this->postJson($this->path('delivery-notes', $firstId).'/validate', null);
        self::assertResponseIsSuccessful();
        self::assertSame('4', $this->onHand(), 'validating the note moves nothing: the invoice already took the goods out');

        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $second = $this->json();
        self::assertSame([[$lathe, '4.000']], array_map(static fn (array $line): array => [$line['sourceInvoiceLineId'], $line['quantity']], $this->listOf($second, 'lines')), 'with no quantities, what is left of every line of goods');
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'nothing is left');

        $this->postJson($this->path('delivery-notes', $this->stringAt($second, 'id')).'/validate', null);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path('invoices', $invoiceId));
        $invoice = $this->json();
        self::assertSame(['whole', 2], [$invoice['delivery'], \count($this->listOf($invoice, 'deliveredBy'))]);
        self::assertSame([[$lathe, '6.000', '0.000', '6.000', '6.000', '0.000']], array_map(static fn (array $line): array => [$line['lineId'], $line['quantity'], $line['returned'], $line['onNotes'], $line['delivered'], $line['left']], $this->listOf($invoice, 'deliveries')));

        $this->postJson($this->path('delivery-notes', $firstId).'/cancel', null);
        self::assertResponseIsSuccessful();
        self::assertSame('4', $this->onHand(), 'cancelling it gives nothing back either');
        $movements = $this->em()->getConnection()->fetchAllAssociative('SELECT source_type, quantity::text AS quantity FROM stock_movement WHERE product_id = ? AND source_type <> ? ORDER BY at', [$this->productId, 'receipt']);
        self::assertSame([['invoice', '-6.000']], array_map(static fn (array $row): array => [$row['source_type'], $row['quantity']], $movements), 'the goods left once, with the invoice');
        $this->getJson($this->path('invoices', $invoiceId));
        self::assertSame('partly', $this->json()['delivery'], 'what a cancelled note took is left to deliver again');
    }

    public function testANoteDeliveringAnInvoiceIsNeverInvoicedAgain(): void
    {
        $this->signedIn(self::SELLER);
        $this->received('10');
        $invoiceId = $this->issued();
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        $noteId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('delivery-notes', $noteId).'/validate', null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->path('invoices').'/from-delivery-notes', ['deliveryNoteIds' => [$noteId]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertStringContainsString('billed its goods', $this->detail());
        $this->postJson($this->path('invoices').'/from-delivery-notes/check', ['deliveryNoteIds' => [$noteId]]);
        self::assertResponseIsSuccessful();
        self::assertSame([['skipped', 'invoiced']], array_map(static fn (array $row): array => [$row['outcome'], $row['reason']], $this->listOf($this->json(), 'rows')), 'the bulk check leaves it out as invoiced');

        $this->postJson($this->path('invoices'), ['customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['description' => 'Port', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '10', 'taxComponentIds' => []]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->path('invoices', $this->stringAt($this->json(), 'id')).'/delivery-notes', ['deliveryNoteIds' => [$noteId]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'nor added to a draft');
        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM invoice WHERE document_type = 'invoice' AND status <> 'draft'"), 'one invoice bills the goods');
    }

    public function testWhatACreditNoteTookBackIsNoLongerDelivered(): void
    {
        $this->signedIn(self::SELLER);
        $this->received('10');
        $invoiceId = $this->issued();
        $lathe = $this->stringAt($this->invoiceLines($invoiceId)[0], 'id');
        $this->postJson($this->deliver($invoiceId), ['quantities' => [$lathe => '1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $creditId = $this->credited($invoiceId, '2');
        self::assertSame('6', $this->onHand(), 'the credit note brought two back');
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['3.000'], array_column($this->listOf($this->json(), 'lines'), 'quantity'), 'six billed, two taken back, one on the first note');
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->getJson($this->path('invoices', $invoiceId));
        self::assertSame([['2.000', '4.000', '0.000']], array_map(static fn (array $line): array => [$line['returned'], $line['onNotes'], $line['left']], $this->listOf($this->json(), 'deliveries')));
        $this->postJson($this->deliver($creditId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a credit note delivers nothing');
        self::assertStringContainsString('credit note', $this->detail());
    }

    /**
     * What a draft takes is checked when it is saved, and again when it is validated: a draft put back from « Récemment
     * supprimés » after another note took the rest, or one a credit note overtook, would deliver what the invoice no
     * longer bills.
     */
    public function testADraftIsCheckedAgainWhenValidatedAgainstWhatItsInvoiceStillLeaves(): void
    {
        // « Récemment supprimés » is read in the company, as every role does.
        $this->signedIn([...self::SELLER, 'company.read']);
        $this->received('10');
        $invoiceId = $this->issued();
        $lathe = $this->stringAt($this->invoiceLines($invoiceId)[0], 'id');

        $this->postJson($this->deliver($invoiceId), ['quantities' => [$lathe => '4']]);
        $binned = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('record-removals'), ['subject' => 'delivery_note', 'ids' => [$binned]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $removal = $this->stringAt($this->json(), 'id');
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        self::assertSame(['6.000'], array_column($this->listOf($this->json(), 'lines'), 'quantity'), 'the deleted draft holds nothing');
        $this->postJson($this->path('delivery-notes', $this->stringAt($this->json(), 'id')).'/validate', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->path('delivery-notes', $binned).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the six billed are all on the other note');
        self::assertStringContainsString('Only 0.000 of this invoice line is left', $this->detail());
        $this->getJson($this->path('delivery-notes', $binned));
        self::assertSame(['draft', null], [$this->json()['status'], $this->json()['number']], 'it stays a draft, and takes no number');

        $other = $this->issued();
        $line = $this->stringAt($this->invoiceLines($other)[0], 'id');
        $this->postJson($this->deliver($other), ['quantities' => [$line => '6']]);
        $overtaken = $this->stringAt($this->json(), 'id');
        $this->credited($other, '2');
        $this->postJson($this->path('delivery-notes', $overtaken).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a credit note issued after the draft took two back');
        self::assertStringContainsString('Only 4.000 of this invoice line is left', $this->detail());
    }

    public function testWhatIsNotAnIssuedInvoiceOfTheCompanyOrNotTheCallersToDeliverIsRefused(): void
    {
        $this->signedIn(self::SELLER);
        $draft = $this->draft();
        $this->postJson($this->deliver($draft), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft bills nothing yet');
        self::assertStringContainsString('not issued', $this->detail());
        $this->postJson($this->deliver(self::ABSENT), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $theirs = $this->issued();
        $this->em()->getConnection()->executeStatement('UPDATE invoice SET company_id = ? WHERE id = ?', [$other->getId()->toRfc4122(), $theirs]);
        $this->postJson($this->deliver($theirs), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s invoice');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery_note'), 'and a refusal drafts nothing');

        $invoiceId = $this->issued();
        $this->createUser('reader@twes.local', 'password-1234', $this->company(), ['invoice.read', 'delivery_note.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a role that may not write notes');
        $this->createUser('carrier@twes.local', 'password-1234', $this->company(), ['delivery_note.read', 'delivery_note.write'], 'carrier');
        $this->login('carrier@twes.local', 'password-1234');
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'a role that may not read invoices');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery_note'));
    }

    public function testARevisedNoteKeepsWhatTheInvoiceBilledAndCarriesNothingElse(): void
    {
        $this->signedIn(self::SELLER);
        $invoiceId = $this->issued();
        $lathe = $this->stringAt($this->invoiceLines($invoiceId)[0], 'id');
        $this->postJson($this->deliver($invoiceId), ['quantities' => [$lathe => '6']]);
        $note = $this->json();
        $noteId = $this->stringAt($note, 'id');
        $line = $this->listOf($note, 'lines')[0];

        $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [[...$this->writableLine($line), 'quantity' => '5', 'description' => 'Tour CNC, carter gris']]]);
        self::assertResponseIsSuccessful();
        self::assertSame([[$lathe, '5.000', 'Tour CNC, carter gris']], array_map(static fn (array $each): array => [$each['sourceInvoiceLineId'], $each['quantity'], $each['description']], $this->listOf($this->json(), 'lines')));

        $note = $this->json();
        $line = $this->listOf($note, 'lines')[0];
        foreach (['quantity' => '7', 'unitPriceNet' => '1000', 'taxComponentIds' => [$this->taxId('TVA19')], 'unitId' => $this->unitId('HUR')] as $field => $value) {
            $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [[...$this->writableLine($line), $field => $value]]]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString("lines[0].$field", $this->detail(), $field);
        }
        $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [$this->writableLine($line), $this->writableLine($line)]]);
        self::assertStringContainsString('lines[1].sourceInvoiceLineId', $this->detail(), 'an invoice line once on a note');
        $free = ['productId' => $this->productId, 'description' => null, 'quantity' => '1', 'unitId' => null, 'unitPriceNet' => null, 'taxComponentIds' => null, 'lotCode' => null];
        $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [$this->writableLine($line), $free]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('lines[1]', $this->detail(), 'goods the invoice did not bill go on another note');

        $this->postJson($this->path('delivery-notes'), [...$this->writable($note), 'customerId' => $this->customer($this->company, 'CLI-0002')->getId()->toRfc4122(), 'lines' => [[...$this->writableLine($line), 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('customerId', $this->detail(), 'an invoice is delivered to its customer');
    }

    public function testThePrintedNoteNamesTheInvoiceItDelivers(): void
    {
        $this->signedIn(self::SELLER);
        $invoiceId = $this->issued();
        $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
        $this->client->request('GET', $this->path('delivery-notes', $this->stringAt($this->json(), 'id')).'/pdf');
        self::assertResponseIsSuccessful();
        $html = (string) end(static::getContainer()->get(FakePdfRenderer::class)->rendered);
        self::assertStringContainsString('<dt>Facture</dt><dd><bdi>'.$this->invoiceNumber($invoiceId).'</bdi>', $html);
        self::assertStringContainsString('250,000', $html, 'an invoice with no discount: the note prints its prices');
    }

    /**
     * A delivery note carries no discount: priced, it would print more than the invoice it names billed, which the
     * customer signs. A note handing over the goods of a discounted invoice prints no prices, a discount on its line or
     * on the whole.
     */
    public function testANoteOfADiscountedInvoicePrintsNoPrices(): void
    {
        $this->signedIn(self::SELLER);
        $discounts = [
            'a rate on its line' => ['lines' => [['productId' => $this->productId, 'quantity' => '6', 'discountRate' => '10']]],
            'an amount on its line' => ['lines' => [['productId' => $this->productId, 'quantity' => '6', 'discountAmount' => '30']]],
            'on the whole' => ['discountAmount' => '100'],
        ];
        foreach ($discounts as $where => $changes) {
            $invoiceId = $this->draft($changes);
            $this->postJson($this->path('invoices', $invoiceId).'/issue', null);
            self::assertResponseIsSuccessful($where);
            $this->postJson($this->deliver($invoiceId), ['quantities' => null]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $where);
            $this->client->request('GET', $this->path('delivery-notes', $this->stringAt($this->json(), 'id')).'/pdf');
            self::assertResponseIsSuccessful();
            $html = (string) end(static::getContainer()->get(FakePdfRenderer::class)->rendered);
            self::assertStringContainsString('<dt>Facture</dt><dd><bdi>'.$this->invoiceNumber($invoiceId).'</bdi>', $html, $where);
            self::assertStringNotContainsString('250,000', $html, $where.': no price the invoice did not bill');
        }
    }

    /** The company as the entity manager knows it now: the test client reboots the kernel between requests. */
    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function deliver(string $invoiceId): string
    {
        return $this->path('invoices', $invoiceId).'/deliveries';
    }

    private function received(string $quantity): void
    {
        $this->getJson($this->path('stock-locations'));
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $this->stringAt($this->jsonList()[0], 'id'), 'quantity' => $quantity]);
        self::assertResponseIsSuccessful();
    }

    /** @param array<string, mixed> $changes */
    private function draft(array $changes = []): string
    {
        $this->postJson($this->path('invoices'), [...[
            'customerId' => $this->customer->getId()->toRfc4122(),
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => 'CMD-7',
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => null,
            'lines' => [
                ['productId' => $this->productId, 'quantity' => '6'],
                ['description' => 'Pose', 'quantity' => '1.5', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA19')]],
            ],
        ], ...$changes]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function issued(): string
    {
        $id = $this->draft();
        $this->postJson($this->path('invoices', $id).'/issue', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** An issued credit note of the invoice taking back `$quantity` of the lathe into stock. */
    private function credited(string $invoiceId, string $quantity): string
    {
        $this->postJson($this->path('invoices', $invoiceId).'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->sendJson('PUT', $this->path('invoices', $id), ['version' => $this->storedVersion('invoice', $id), 'customerId' => $this->customer->getId()->toRfc4122(), 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => null, 'lines' => [['productId' => $this->productId, 'quantity' => $quantity, 'returned' => true]]]);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('invoices', $id).'/issue', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @return list<array<string, mixed>> */
    private function invoiceLines(string $invoiceId): array
    {
        $this->getJson($this->path('invoices', $invoiceId));
        self::assertResponseIsSuccessful();

        return $this->listOf($this->json(), 'lines');
    }

    private function invoiceNumber(string $invoiceId): string
    {
        $number = $this->em()->getConnection()->fetchOne('SELECT number FROM invoice WHERE id = ?', [$invoiceId]);
        self::assertIsString($number);

        return $number;
    }

    /** What is on hand of the lathe, everywhere. */
    private function onHand(): string
    {
        $sum = $this->em()->getConnection()->fetchOne('SELECT COALESCE(SUM(quantity), 0)::numeric(14, 0)::text FROM stock_movement WHERE product_id = ?', [$this->productId]);
        self::assertIsString($sum);

        return $sum;
    }

    private function detail(): string
    {
        return (string) $this->client->getResponse()->getContent();
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function listOf(array $body, string $key): array
    {
        $list = [];
        foreach ($this->arrayAt($body, $key) as $item) {
            self::assertIsArray($item);
            $row = [];
            foreach ($item as $name => $value) {
                $row[(string) $name] = $value;
            }
            $list[] = $row;
        }

        return $list;
    }

    /**
     * What a form sends back of a note it read.
     *
     * @param array<string, mixed> $note
     *
     * @return array<string, mixed>
     */
    private function writable(array $note): array
    {
        return array_intersect_key($note, array_flip(['version', 'customerId', 'establishmentId', 'deliveryDate', 'deliveryAddressLine1', 'deliveryAddressLine2', 'deliveryPostalCode', 'deliveryCity', 'deliveryCountryCode', 'customerReference', 'remarksPrinted', 'notesInternal']));
    }

    /**
     * @param array<string, mixed> $line
     *
     * @return array<string, mixed>
     */
    private function writableLine(array $line): array
    {
        return array_intersect_key($line, array_flip(['productId', 'description', 'quantity', 'unitId', 'unitPriceNet', 'taxComponentIds', 'lotCode', 'sourceInvoiceLineId']));
    }

    private function customer(Company $company, string $number): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $company = $this->em()->find(Company::class, $company->getId());
        self::assertNotNull($company);
        $customer = Customer::create($company, $number, self::aTunisianBusiness('Carthage Conseil '.$number), null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function unit(string $code): Unit
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function unitId(string $code): string
    {
        return $this->unit($code)->getId()->toRfc4122();
    }

    private function tax(string $code): TaxComponent
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax;
    }

    private function taxId(string $code): string
    {
        return $this->tax($code)->getId()->toRfc4122();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(string $resource, ?string $id = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource.(null === $id ? '' : '/'.$id);
    }
}
