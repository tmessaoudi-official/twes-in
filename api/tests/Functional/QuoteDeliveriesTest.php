<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Fiscal\Domain\Calculation\Decimal;
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
 * An accepted quote is the order: it is delivered by notes on as many days as it takes, each line no more than is left of
 * it, its goods leave stock once, with the notes, and the invoices of those notes bill the price and the discount the
 * customer accepted, the parts of an amount discount summing to the whole.
 */
final class QuoteDeliveriesTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';
    private const array SELLER = [
        'quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate',
        'invoice.read', 'invoice.write', 'invoice.issue', 'stock.read', 'stock.write',
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

    public function testAnAcceptedQuoteIsDeliveredInPartsAndNeverBeyondWhatIsLeft(): void
    {
        $this->signedIn(self::SELLER);
        $quoteId = $this->accepted();
        [$lathe, $pose] = $this->quoteLineIds($quoteId);

        $this->postJson($this->deliver($quoteId), ['quantities' => [$lathe => '2']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = $this->json();
        self::assertSame('draft', $first['status']);
        self::assertSame([[$lathe, '2.000', '1250.0000', 'Tour CNC']], array_map(static fn (array $line): array => [$line['sourceQuoteLineId'], $line['quantity'], $line['unitPriceNet'], $line['description']], $this->listOf($first, 'lines')));
        self::assertSame(['quoteId' => $quoteId, 'number' => $this->quoteNumber($quoteId)], $first['quote'], 'the note links to the quote it delivers');
        self::assertSame('RFQ-12', $first['customerReference'], 'for the reference the customer gave the quote');

        $this->getJson($this->path('quotes', $quoteId).'/left');
        self::assertResponseIsSuccessful();
        self::assertSame([[$lathe, '6.000', '2.000', '0.000', '4.000'], [$pose, '1.500', '0.000', '0.000', '1.500']], array_map(static fn (array $line): array => [$line['lineId'], $line['quantity'], $line['onNotes'], $line['delivered'], $line['left']], $this->listOf($this->json(), 'lines')), 'a draft holds what it took');

        $this->postJson($this->deliver($quoteId), ['quantities' => [$lathe => '4.001']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('quantities: Only 4.000', $this->detail());
        $this->postJson($this->deliver($quoteId), ['quantities' => [self::ABSENT => '1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->postJson($this->deliver($quoteId), ['quantities' => [$lathe => '0']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $this->postJson($this->deliver($quoteId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([[$lathe, '4.000'], [$pose, '1.500']], array_map(static fn (array $line): array => [$line['sourceQuoteLineId'], $line['quantity']], $this->listOf($this->json(), 'lines')), 'with no quantities, what is left of every line');
        $this->postJson($this->deliver($quoteId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'nothing is left');

        $this->getJson($this->path('quotes', $quoteId));
        $quote = $this->json();
        self::assertSame(['none', 2], [$quote['delivery'], \count($this->listOf($quote, 'deliveryNotes'))], 'drafts are listed, and deliver nothing yet');
        $this->postJson($this->path('delivery-notes', $this->stringAt($first, 'id')).'/validate', null);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path('quotes', $quoteId));
        self::assertSame('partly', $this->json()['delivery']);
        self::assertSame([['2.000', '6.000'], ['0.000', '1.500']], array_map(static fn (array $line): array => [$line['delivered'], $line['onNotes']], $this->listOf($this->json(), 'lines')));
    }

    public function testARevisedNoteKeepsTheQuoteLineItDeliversAndWhatTheCustomerAccepted(): void
    {
        $this->signedIn(self::SELLER);
        $quoteId = $this->accepted();
        [$lathe] = $this->quoteLineIds($quoteId);
        $this->postJson($this->deliver($quoteId), ['quantities' => [$lathe => '6']]);
        $note = $this->json();
        $noteId = $this->stringAt($note, 'id');

        $line = $this->listOf($note, 'lines')[0];
        $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [[...$this->writableLine($line), 'quantity' => '5', 'description' => 'Tour CNC, carter gris']]]);
        self::assertResponseIsSuccessful();
        self::assertSame([[$lathe, '5.000', 'Tour CNC, carter gris']], array_map(static fn (array $each): array => [$each['sourceQuoteLineId'], $each['quantity'], $each['description']], $this->listOf($this->json(), 'lines')), 'the line keeps its quote line through a revision');

        $note = $this->json();
        $line = $this->listOf($note, 'lines')[0];
        $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [[...$this->writableLine($line), 'quantity' => '6']]]);
        self::assertResponseIsSuccessful('its own quantity is not counted against what is left');
        $note = $this->json();
        $line = $this->listOf($note, 'lines')[0];
        // Seven of six: the lathe counts in whole pieces, so 6.001 would be refused for its decimals, not for what is left.
        foreach (['quantity' => '7', 'unitPriceNet' => '1000', 'taxComponentIds' => [$this->taxId('TVA19')], 'unitId' => $this->unitId('HUR')] as $field => $value) {
            $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [[...$this->writableLine($line), $field => $value]]]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString("lines[0].$field", $this->detail(), $field);
        }
        $this->sendJson('PUT', $this->path('delivery-notes', $noteId), [...$this->writable($note), 'lines' => [$this->writableLine($line), $this->writableLine($line)]]);
        self::assertStringContainsString('lines[1].sourceQuoteLineId', $this->detail(), 'a quote line once on a note');

        $this->postJson($this->path('delivery-notes'), [...$this->writable($note), 'customerId' => $this->customer($this->company, 'CLI-0002')->getId()->toRfc4122(), 'lines' => [[...$this->writableLine($line), 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('customerId', $this->detail(), 'a quote is delivered to its customer');
    }

    public function testWhatIsNotAnAcceptedQuoteOfTheCompanyOrNotTheCallersToDeliverIsRefused(): void
    {
        $this->signedIn(self::SELLER);
        $sent = $this->sent();
        $this->postJson($this->deliver($sent), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a quote not accepted');
        $this->postJson($this->deliver(self::ABSENT), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('quotes', self::ABSENT).'/left');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $other = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($other);
        $theirs = $this->accepted();
        $this->em()->getConnection()->executeStatement('UPDATE quote SET company_id = ? WHERE id = ?', [$other->getId()->toRfc4122(), $theirs]);
        $this->postJson($this->deliver($theirs), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s quote');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery_note'), 'and a refusal drafts nothing');

        $quoteId = $this->accepted();
        $this->createUser('reader@twes.local', 'password-1234', $this->company(), ['quote.read', 'delivery_note.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');
        $this->postJson($this->deliver($quoteId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a role that may not write notes');
        $this->createUser('carrier@twes.local', 'password-1234', $this->company(), ['delivery_note.read', 'delivery_note.write'], 'carrier');
        $this->login('carrier@twes.local', 'password-1234');
        $this->postJson($this->deliver($quoteId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, 'a role that may not read quotes');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM delivery_note'));
    }

    public function testAQuoteWithADocumentDiscountOrADepositIsInvoicedWholeAndNotDelivered(): void
    {
        $this->signedIn(self::SELLER);
        $discounted = $this->accepted(['discountAmount' => '10']);
        $this->postJson($this->deliver($discounted), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('discountAmount: ', $this->detail());

        $deposited = $this->accepted();
        $this->postJson($this->path('quotes', $deposited).'/deposit-invoices', ['depositPercentage' => '30', 'depositAmount' => null]);
        self::assertResponseIsSuccessful();
        $this->postJson($this->deliver($deposited), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('deposits: ', $this->detail());

        $delivered = $this->accepted();
        $this->postJson($this->deliver($delivered), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->path('quotes', $delivered).'/deposit-invoices', ['depositPercentage' => '30', 'depositAmount' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'nor a deposit drawn once notes stand');
    }

    public function testAQuoteIsInvoicedThroughItsNotesOrAsAWholeNeverBoth(): void
    {
        $this->signedIn(self::SELLER);
        $delivered = $this->accepted();
        $this->postJson($this->deliver($delivered), ['quantities' => null]);
        $noteId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('quotes', $delivered).'/invoice', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a quote its notes deliver is invoiced through them');
        self::assertStringContainsString('delivered by delivery notes', $this->detail());
        // An open draft of the same customer: another quote invoiced whole.
        $other = $this->accepted();
        $this->postJson($this->path('quotes', $other).'/invoice', null);
        self::assertResponseIsSuccessful();
        $draftId = $this->stringAt($this->json(), 'invoiceId');
        $this->postJson($this->path('quotes', $delivered).'/invoices/'.$draftId, null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'nor added to an open draft');

        $this->postJson($this->path('delivery-notes', $noteId).'/cancel', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('quotes', $delivered).'/invoice', null);
        self::assertResponseIsSuccessful('once its notes are cancelled, it is invoiced whole');

        $invoiced = $this->accepted();
        $this->postJson($this->path('quotes', $invoiced).'/invoice', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->deliver($invoiced), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a quote invoiced whole draws no note');
    }

    /**
     * What a draft takes is checked when it is saved, and again when it is validated: a draft put back from « Récemment
     * supprimés » would otherwise deliver what another note took meanwhile, or a quote invoiced as a whole meanwhile,
     * its goods then leaving stock twice and billed twice.
     */
    public function testADraftPutBackFromTheBinIsCheckedAgainWhenValidated(): void
    {
        // « Récemment supprimés » is read in the company, as every role does.
        $this->signedIn([...self::SELLER, 'company.read']);
        $quoteId = $this->accepted();
        [$lathe] = $this->quoteLineIds($quoteId);
        $this->postJson($this->deliver($quoteId), ['quantities' => [$lathe => '4']]);
        $binned = $this->stringAt($this->json(), 'id');
        $removal = $this->binned($binned);
        $this->postJson($this->deliver($quoteId), ['quantities' => [$lathe => '6']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the deleted draft holds nothing');
        $this->postJson($this->path('delivery-notes', $this->stringAt($this->json(), 'id')).'/validate', null);
        self::assertResponseIsSuccessful();
        $onHand = $this->onHand();
        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->path('delivery-notes', $binned).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the six accepted are all on the other note');
        self::assertStringContainsString('Only 0.000 of this quote line is left', $this->detail());
        $this->getJson($this->path('delivery-notes', $binned));
        self::assertSame(['draft', null], [$this->json()['status'], $this->json()['number']], 'it stays a draft, and takes no number');
        self::assertSame($onHand, $this->onHand(), 'and nothing left stock');

        $whole = $this->accepted();
        $this->postJson($this->deliver($whole), ['quantities' => null]);
        $binned = $this->stringAt($this->json(), 'id');
        $removal = $this->binned($binned);
        $this->postJson($this->path('quotes', $whole).'/invoice', null);
        self::assertResponseIsSuccessful('with its only note deleted, the quote is invoiced as a whole');
        $this->postJson($this->path('record-removals/'.$removal.'/restore'), null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path('delivery-notes', $binned).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'its goods are delivered from that invoice');
        self::assertStringContainsString('is on an invoice drafted from it as a whole', $this->detail());
    }

    public function testTheGoodsLeaveOnceWithTheNotesAndTheirInvoicesBillThePriceAndTheDiscountAccepted(): void
    {
        $this->signedIn(self::SELLER);
        $this->getJson($this->path('stock-locations'));
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $this->stringAt($this->jsonList()[0], 'id'), 'quantity' => '10']);
        self::assertResponseIsSuccessful();
        // 10 of discount over 3 pieces delivered one at a time: what is due so far less what was given, 3.333, then 6.667 - 3.333 = 3.334, then the rest, 3.333.
        $quoteId = $this->accepted(['lines' => [
            ['productId' => $this->productId, 'quantity' => '3', 'discountAmount' => '10'],
            ['description' => 'Pose', 'quantity' => '3', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA19')], 'discountRate' => '5'],
        ]]);
        [$lathe, $pose] = $this->quoteLineIds($quoteId);

        $invoices = [];
        foreach ([1, 2, 3] as $day) {
            $this->postJson($this->deliver($quoteId), ['quantities' => [$lathe => '1', $pose => '1']]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $noteId = $this->stringAt($this->json(), 'id');
            $this->postJson($this->path('delivery-notes', $noteId).'/validate', null);
            self::assertResponseIsSuccessful();
            self::assertSame((string) (10 - $day), $this->onHand(), 'each note takes out what it delivers');
            $this->postJson($this->path('invoices').'/from-delivery-notes', ['deliveryNoteIds' => [$noteId]]);
            self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
            $invoiceId = $this->stringAt($this->json(), 'id');
            $this->postJson($this->path('invoices', $invoiceId).'/issue', null);
            self::assertResponseIsSuccessful();
            $invoices[] = $invoiceId;
        }

        self::assertSame('7', $this->onHand(), 'the invoices of the notes take nothing more out');
        $movements = $this->em()->getConnection()->fetchAllAssociative('SELECT source_type, quantity::text AS quantity FROM stock_movement WHERE product_id = ? AND source_type <> ? ORDER BY at', [$this->productId, 'receipt']);
        self::assertSame([['delivery_note', '-1.000'], ['delivery_note', '-1.000'], ['delivery_note', '-1.000']], array_map(static fn (array $row): array => [$row['source_type'], $row['quantity']], $movements));

        $lines = [];
        foreach ($invoices as $invoiceId) {
            $this->getJson($this->path('invoices', $invoiceId));
            foreach ($this->listOf($this->json(), 'lines') as $line) {
                $lines[] = [$line['description'], $line['unitPriceNet'], $line['discountRate'], $line['discountAmount'], $line['net']];
            }
        }
        self::assertSame([
            ['Tour CNC', '1250.0000', null, '3.333', '1246.667'],
            ['Pose', '40.0000', '5.000', null, '38.000'],
            ['Tour CNC', '1250.0000', null, '3.334', '1246.666'],
            ['Pose', '40.0000', '5.000', null, '38.000'],
            ['Tour CNC', '1250.0000', null, '3.333', '1246.667'],
            ['Pose', '40.0000', '5.000', null, '38.000'],
        ], $lines);
        $this->getJson($this->path('quotes', $quoteId));
        $quote = $this->json();
        self::assertSame('whole', $quote['delivery']);
        self::assertSame(
            Decimal::format(Decimal::of($this->stringAt($quote, 'subtotalNet')), 3),
            Decimal::format(Decimal::sum(array_map(static fn (array $line): \BcMath\Number => Decimal::of((string) $line[4]), $lines)), 3),
            'the three deliveries bill what the customer accepted, net of every discount',
        );
    }

    public function testTheRestOfANoteLineAddedToTheDraftOfItsFirstPartKeepsTheWholeDiscountAccepted(): void
    {
        $this->signedIn(self::SELLER);
        $this->getJson($this->path('stock-locations'));
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $this->stringAt($this->jsonList()[0], 'id'), 'quantity' => '10']);
        self::assertResponseIsSuccessful();
        $quoteId = $this->accepted(['lines' => [['productId' => $this->productId, 'quantity' => '10', 'discountAmount' => '100']]]);
        $this->postJson($this->deliver($quoteId), ['quantities' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $noteId = $this->stringAt($this->json(), 'id');
        $noteLine = $this->em()->getConnection()->fetchOne('SELECT id FROM delivery_note_line WHERE delivery_note_id = ?', [$noteId]);
        self::assertIsString($noteLine);
        $this->postJson($this->path('delivery-notes', $noteId).'/validate', null);
        self::assertResponseIsSuccessful();

        $this->postJson($this->path('invoices').'/from-delivery-notes', ['deliveryNoteIds' => [$noteId], 'quantities' => [$noteLine => '4']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $draftId = $this->stringAt($this->json(), 'id');
        self::assertSame('40.000', $this->listOf($this->json(), 'lines')[0]['discountAmount'], 'four of ten take four tenths of the discount');

        // The rest joins the same line of the same draft, which then bills the whole line: the whole discount with it.
        $this->postJson($this->path('invoices', $draftId).'/delivery-notes', ['deliveryNoteIds' => [$noteId]]);
        self::assertResponseIsSuccessful();
        $lines = $this->listOf($this->json(), 'lines');
        self::assertCount(1, $lines);
        self::assertSame(['10.000', '100.000', '12400.000'], [$lines[0]['quantity'], $lines[0]['discountAmount'], $lines[0]['net']]);
    }

    public function testThePrintedNoteNamesTheQuoteItDelivers(): void
    {
        $this->signedIn(self::SELLER);
        $quoteId = $this->accepted();
        $this->postJson($this->deliver($quoteId), ['quantities' => null]);
        $this->client->request('GET', $this->path('delivery-notes', $this->stringAt($this->json(), 'id')).'/pdf');
        self::assertResponseIsSuccessful();
        $html = (string) end(static::getContainer()->get(FakePdfRenderer::class)->rendered);
        self::assertStringContainsString('<dt>Devis</dt><dd><bdi>'.$this->quoteNumber($quoteId).'</bdi>', $html);
    }

    /** The company as the entity manager knows it now: the test client reboots the kernel between requests. */
    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    /** Deletes the draft note into « Récemment supprimés »; the removal's id, which puts it back. */
    private function binned(string $noteId): string
    {
        $this->postJson($this->path('record-removals'), ['subject' => 'delivery_note', 'ids' => [$noteId]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function deliver(string $quoteId): string
    {
        return $this->path('quotes', $quoteId).'/delivery-notes';
    }

    /** @return list<string> */
    private function quoteLineIds(string $quoteId): array
    {
        $this->getJson($this->path('quotes', $quoteId));
        self::assertResponseIsSuccessful();

        return array_map(fn (array $line): string => $this->stringAt($line, 'id'), $this->listOf($this->json(), 'lines'));
    }

    private function quoteNumber(string $quoteId): string
    {
        $number = $this->em()->getConnection()->fetchOne('SELECT number FROM quote WHERE id = ?', [$quoteId]);
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
        return array_intersect_key($line, array_flip(['productId', 'description', 'quantity', 'unitId', 'unitPriceNet', 'taxComponentIds', 'lotCode', 'sourceQuoteLineId']));
    }

    /** @param array<string, mixed> $changes */
    private function sent(array $changes = []): string
    {
        $this->postJson($this->path('quotes'), [...[
            'customerId' => $this->customer->getId()->toRfc4122(),
            'establishmentId' => null,
            'customerReference' => 'RFQ-12',
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'lines' => [
                ['productId' => $this->productId, 'quantity' => '6', 'discountAmount' => '30'],
                ['description' => 'Pose', 'quantity' => '1.5', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA19')], 'discountRate' => '10'],
            ],
        ], ...$changes]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path('quotes', $id).'/send', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @param array<string, mixed> $changes */
    private function accepted(array $changes = []): string
    {
        $id = $this->sent($changes);
        $this->postJson($this->path('quotes', $id).'/accept', ['answeredOn' => null]);
        self::assertResponseIsSuccessful();

        return $id;
    }

    private function customer(Company $company, string $number): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        // The test client's requests reboot the kernel: the company is the one its entity manager holds now.
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
