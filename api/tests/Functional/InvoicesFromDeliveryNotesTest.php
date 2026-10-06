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
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNotePrint;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Products\Domain\ProductTracking;
use App\Shared\Domain\PrintSettings;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;

/** docs/SPEC.md § 7 (2026-09-14): an invoice drafted from delivery notes, and the notes it invoices once it is issued. */
final class InvoicesFromDeliveryNotesTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';

    private Company $company;
    private string $customerId;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customerId = $this->customer('CLI-0001')->getId()->toRfc4122();
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $this->unit('C62'), null, [$this->tax('FODEC')->getId(), $this->tax('TVA19')->getId()], new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testAnInvoiceDraftedFromNotesCopiesTheirLinesAndIssuingItInvoicesThem(): void
    {
        $this->signedIn();
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $first = $this->validatedNote(['customerReference' => 'PO-7', 'lines' => [
            ['productId' => $this->productId, 'quantity' => '2'],
            ['description' => 'Transport', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '30', 'taxComponentIds' => [$this->taxId('TVA19')]],
        ]]);
        $this->postJson($this->notePath($first).'/deliver', ['deliveredOn' => $today]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $second = $this->validatedNote(['customerReference' => 'PO-7', 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]);
        $sources = [...$this->lineIds($first), ...$this->lineIds($second)];

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$second, $first]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invoice = $this->json();
        $id = $this->stringAt($invoice, 'id');
        self::assertSame(['invoice', 'draft', $this->customerId, $this->establishmentId(), $today, 'PO-7'], [$invoice['type'], $invoice['status'], $invoice['customerId'], $invoice['establishmentId'], $invoice['supplyDate'], $invoice['customerReference']]);
        $lines = $this->arrayAt($invoice, 'lines');
        self::assertSame($sources, array_column($lines, 'sourceDeliveryNoteLineId'), 'the notes in the order they were numbered, each line where it came from');
        self::assertSame(['Portable 14"', 'Transport', 'Portable 14"'], array_column($lines, 'description'));
        self::assertSame(['2.000', '1.000', '1.000'], array_column($lines, 'quantity'));
        self::assertSame(['1250.0000', '30.0000', '1250.0000'], array_column($lines, 'unitPriceNet'));
        self::assertSame([[$this->taxId('FODEC'), $this->taxId('TVA19')], [$this->taxId('TVA19')], [$this->taxId('FODEC'), $this->taxId('TVA19')]], array_column($lines, 'taxComponentIds'));
        self::assertSame([null, null, null], array_column($lines, 'discountRate'));
        self::assertSame([$this->taxId('TIMBRE')], $invoice['documentTaxComponentIds'], "the company's default document taxes");
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.created'");
        self::assertIsString($changes);
        self::assertEquals(['deliveryNoteIds' => [$first, $second]], json_decode($changes, true));

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$first]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a note already on a draft is not invoiced twice');
        $this->postJson($this->notePath($second).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a note on an invoice is not cancelled');

        $echo = [];
        foreach ($lines as $line) {
            self::assertIsArray($line);
            $echo[] = array_diff_key($line, ['net' => true]);
        }
        $this->sendJson('PUT', $this->invoicePath($id), [...$this->invoiceBody($invoice), 'lines' => $echo, 'notesPrinted' => 'Merci']);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame($sources, array_column($this->arrayAt($this->json(), 'lines'), 'sourceDeliveryNoteLineId'), 'a revision echoing the lines keeps where they came from');
        $foreign = $echo;
        $foreign[0]['sourceDeliveryNoteLineId'] = self::ABSENT;
        $this->sendJson('PUT', $this->invoicePath($id), [...$this->invoiceBody($invoice), 'lines' => $foreign]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a line never claims a delivery note line its draft did not carry');
        self::assertStringContainsString('sourceDeliveryNoteLineId', (string) $this->client->getResponse()->getContent());
        $this->postJson($this->invoicePath(), [...$this->invoiceBody($invoice), 'lines' => [$echo[0]]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'nor does a new invoice');

        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $number = $this->stringAt($this->json(), 'number');

        foreach ([$first, $second] as $note) {
            $this->getJson($this->notePath($note));
            self::assertSame(['invoiced', $id], [$this->json()['status'], $this->json()['invoicedByInvoiceId']]);
        }
        $invoiced = $this->em()->getConnection()->fetchFirstColumn("SELECT changes::text FROM audit_log WHERE action = 'delivery_note.invoiced' ORDER BY entity_id");
        self::assertCount(2, $invoiced);
        foreach ($invoiced as $entry) {
            self::assertIsString($entry);
            self::assertEquals(['invoiceId' => $id, 'number' => $number], json_decode($entry, true));
        }
        $this->postJson($this->notePath($second).'/deliver', []);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an invoiced note is not delivered');
        $this->postJson($this->notePath($first).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an invoiced note is not cancelled');
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$first]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an invoiced note is not invoiced again');

        $this->postJson($this->invoicePath($id).'/credit-notes', ['creditNoteReason' => 'Retour']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([null, null, null], array_column($this->arrayAt($this->json(), 'lines'), 'sourceDeliveryNoteLineId'), 'a credit note invoices no delivery note');
    }

    /** docs/SPEC.md § 7: an invoice takes all or part of a note's lines, and the note is invoiced once nothing is left. */
    public function testANoteIsAddedToAnExistingDraftAndBothAreInvoicedOnceItIsIssuedButNeverToAnIssuedInvoice(): void
    {
        $this->signedIn();
        $first = $this->validatedNote(['lines' => [['productId' => $this->productId, 'quantity' => '2']]]);
        $second = $this->validatedNote(['lines' => [['description' => 'Transport', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '30', 'taxComponentIds' => [$this->taxId('TVA19')]]]]);
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$first]]);
        $id = $this->stringAt($this->json(), 'id');
        $toDraft = $this->invoicePath($id).'/delivery-notes';

        $this->postJson($toDraft, ['deliveryNoteIds' => [$second]]);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $invoice = $this->json();
        self::assertSame([$id, 'draft'], [$invoice['id'], $invoice['status']]);
        self::assertSame([...$this->lineIds($first), ...$this->lineIds($second)], array_column($this->arrayAt($invoice, 'lines'), 'sourceDeliveryNoteLineId'), 'the draft\'s own lines first, then the added ones');
        $revised = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.revised'");
        self::assertIsString($revised);
        self::assertEquals(['fields' => ['lines'], 'deliveryNoteIds' => [$second]], json_decode($revised, true));
        $this->postJson($toDraft, ['deliveryNoteIds' => [$second]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'what the draft already took is not added twice');

        $this->postJson($this->invoicePath($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        foreach ([$first, $second] as $note) {
            $this->getJson($this->notePath($note));
            self::assertSame(['invoiced', $id], [$this->json()['status'], $this->json()['invoicedByInvoiceId']]);
        }
        $third = $this->validatedNote();
        $this->postJson($toDraft, ['deliveryNoteIds' => [$third]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an issued invoice is corrected by a credit note, never added to');
        $this->getJson($this->invoicePath($id));
        self::assertCount(2, $this->arrayAt($this->json(), 'lines'), 'nothing was added');
    }

    public function testAddingNotesToADraftNeedsInvoiceWriteTheCompanysOwnInvoiceAndNotesOfItsCustomer(): void
    {
        $this->signedIn();
        // Made before the first request: the kernel reboots between requests and detaches the company.
        $other = $this->customer('CLI-0002')->getId()->toRfc4122();
        $first = $this->validatedNote();
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$first]]);
        $id = $this->stringAt($this->json(), 'id');
        $foreign = $this->validatedNote(['customerId' => $other]);

        $this->postJson($this->invoicePath($id).'/delivery-notes', ['deliveryNoteIds' => [$foreign]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a note of another customer');
        self::assertStringContainsString('invoiceId', (string) $this->client->getResponse()->getContent());
        $this->postJson($this->invoicePath($id).'/delivery-notes', ['deliveryNoteIds' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'no note at all');
        $this->postJson($this->invoicePath(self::ABSENT).'/delivery-notes', ['deliveryNoteIds' => [$this->validatedNote()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'an invoice that does not exist');

        $globex = $this->createCompany('Globex');
        $this->createUser('globex@twes.local', 'password-1234', $globex, ['invoice.read', 'invoice.write', 'delivery_note.read'], 'member');
        $this->sendJson('POST', '/api/auth/logout');
        $this->login('globex@twes.local', 'password-1234');
        $this->postJson('/api/companies/'.$globex->getId()->toRfc4122().'/invoices/'.$id.'/delivery-notes', ['deliveryNoteIds' => [$first]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s invoice');
    }

    public function testAnInvoiceTakesPartOfANoteAndTheNoteIsInvoicedOnceNothingIsLeft(): void
    {
        $this->signedIn();
        $note = $this->validatedNote(['lines' => [
            ['productId' => $this->productId, 'quantity' => '10'],
            ['description' => 'Transport', 'quantity' => '4', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '30', 'taxComponentIds' => []],
        ]]);
        [$goods, $transport] = $this->lineIds($note);

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note], 'quantities' => [$goods => '6', $transport => '4']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = $this->json();
        self::assertSame(['6.000', '4.000'], array_column($this->arrayAt($first, 'lines'), 'quantity'));
        self::assertSame([$goods, $transport], array_column($this->arrayAt($first, 'lines'), 'sourceDeliveryNoteLineId'));

        $this->getJson($this->notePath($note).'/left');
        self::assertResponseIsSuccessful();
        self::assertSame(
            [[$goods, '10.000', '6.000', '4.000'], [$transport, '4.000', '4.000', '0.000']],
            array_map(null, array_column($this->arrayAt($this->json(), 'lines'), 'lineId'), array_column($this->arrayAt($this->json(), 'lines'), 'quantity'), array_column($this->arrayAt($this->json(), 'lines'), 'invoiced'), array_column($this->arrayAt($this->json(), 'lines'), 'left')),
            'what a draft holds is already taken',
        );

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note], 'quantities' => [$goods => '7']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'only 4 are left: what a draft already holds is not offered again');
        self::assertStringContainsString('quantities', (string) $this->client->getResponse()->getContent());
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note], 'quantities' => [self::ABSENT => '1']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a quantity names a line of the notes asked for');
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note], 'quantities' => [$goods => '0']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a quantity is more than nothing');

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'with no quantities, what is left of every line');
        $second = $this->json();
        self::assertSame(['4.000'], array_column($this->arrayAt($second, 'lines'), 'quantity'), 'the transport is fully on the first draft, so it is not on this one');
        self::assertSame([$goods], array_column($this->arrayAt($second, 'lines'), 'sourceDeliveryNoteLineId'));

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'nothing is left to invoice');

        $this->postJson($this->invoicePath($this->stringAt($first, 'id')).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->getJson($this->notePath($note));
        self::assertSame([null, 'validated'], [$this->json()['invoicedByInvoiceId'], $this->json()['status']], 'part of it is still to invoice');

        $this->postJson($this->invoicePath($this->stringAt($second, 'id')).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->getJson($this->notePath($note));
        self::assertSame(['invoiced', $this->stringAt($second, 'id')], [$this->json()['status'], $this->json()['invoicedByInvoiceId']], 'invoiced by the invoice that took the last of it');
    }

    /**
     * A draft's line taken from a delivery note stays that line (docs/SPEC.md § 7, audit 2026-10-06 A-16): revising it
     * cannot invoice more than the note line has left, counting the company's other invoices that are not cancelled, nor
     * turn it into another product.
     */
    public function testRevisingADraftCannotInvoiceMoreOfANoteLineThanIsLeftNorChangeItsProduct(): void
    {
        $this->signedIn();
        $note = $this->validatedNote(['lines' => [['productId' => $this->productId, 'quantity' => '10']]]);
        [$goods] = $this->lineIds($note);
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note], 'quantities' => [$goods => '6']]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = $this->json();
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'the other four on a second draft');
        $line = $this->arrayAt($first, 'lines')[0];
        self::assertIsArray($line);
        $line = array_diff_key($line, ['net' => true]);
        $revise = fn (array $lines) => $this->sendJson('PUT', $this->invoicePath($this->stringAt($first, 'id')), [...$this->invoiceBody($first), 'lines' => $lines]);

        $revise([[...$line, 'quantity' => '7']]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'four of the ten are on the other draft');
        self::assertStringContainsString('lines[0].quantity', (string) $this->client->getResponse()->getContent());
        self::assertStringContainsString('6.000', (string) $this->client->getResponse()->getContent());
        $other = Product::create($this->em()->find(Company::class, $this->company->getId()) ?? self::fail('no company'), 'ART-002', new ProductDetails('Souris', null, ProductKind::Goods, '20'), $this->unit('C62'), null, [], new \DateTimeImmutable());
        $this->em()->persist($other);
        $this->em()->flush();
        $revise([[...$line, 'productId' => $other->getId()->toRfc4122()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a note line keeps its product');
        self::assertStringContainsString('lines[0].productId', (string) $this->client->getResponse()->getContent());

        $revise([[...$line, 'quantity' => '5']]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'less is always fine');
        $revise([[...$line, 'quantity' => '6']]);
        self::assertResponseStatusCodeSame(Response::HTTP_OK, 'its own six are its own, not taken twice');
    }

    public function testAnInvoiceDraftedFromNotesNamesTheLotEachLineHandedOverAndARevisionKeepsIt(): void
    {
        // docs/SPEC.md § 7, 2026-09-24 12:40 row 5: the lot handed over is the lot invoiced.
        $this->signedIn();
        $product = $this->em()->find(Product::class, $this->productId);
        self::assertNotNull($product);
        $product->track(ProductTracking::Serial, new \DateTimeImmutable());
        $this->em()->flush();
        $note = $this->validatedNote(['lines' => [
            ['productId' => $this->productId, 'quantity' => '1', 'lotCode' => 'SN-7'],
            ['productId' => $this->productId, 'quantity' => '1'],
        ]]);

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invoice = $this->json();
        $lines = $this->arrayAt($invoice, 'lines');
        self::assertSame(['SN-7', null], array_column($lines, 'lotCode'));
        self::assertSame(['serial', 'serial'], array_column($lines, 'productTracking'));

        $echo = array_map(static fn (mixed $line): array => array_diff_key(\is_array($line) ? $line : [], ['net' => true]), $lines);
        $this->sendJson('PUT', $this->invoicePath($this->stringAt($invoice, 'id')), [...$this->invoiceBody($invoice), 'lines' => $echo]);
        self::assertResponseIsSuccessful();
        self::assertSame(['SN-7', null], array_column($this->arrayAt($this->json(), 'lines'), 'lotCode'), 'what was read is saved back as it was');
    }

    public function testACancelledDraftFreesItsNotesAndWhatCannotBeInvoicedIsRefused(): void
    {
        $other = $this->customer('CLI-0002')->getId()->toRfc4122();
        $this->signedIn();
        $note = $this->validatedNote();

        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->postJson($this->invoicePath($this->stringAt($this->json(), 'id')).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$note]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a cancelled draft strands no note');
        $this->postJson($this->notePath($note).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->postJson($this->notePath(), $this->note(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $draft = $this->stringAt($this->json(), 'id');
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$draft]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft note is not invoiced');
        $cancelled = $this->validatedNote();
        $this->postJson($this->notePath($cancelled).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$cancelled]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a cancelled note is not invoiced');

        $mine = $this->validatedNote();
        $theirs = $this->validatedNote(['customerId' => $other]);
        foreach ([
            'notes of two customers' => [$mine, $theirs],
            'no note' => [],
            'a note named twice' => [$mine, $mine],
            'a note of no one' => [self::ABSENT],
            'not an id' => ['not-a-uuid'],
        ] as $case => $ids) {
            $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => $ids]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $case);
            self::assertStringContainsString('deliveryNoteIds', (string) $this->client->getResponse()->getContent(), $case);
        }
    }

    public function testInvoicingNotesNeedsInvoiceWriteBothModulesAndTheCompanysOwnNotes(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $theirs = $this->persistedValidatedNote($globex);
        $mine = $this->persistedValidatedNote($this->company);

        // Both users exist before the first request: the test client's kernel reboots between requests.
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['invoice.read', 'delivery_note.read', 'delivery_note.write'], 'reader');
        $this->createSales();
        $this->login('reader@twes.local', 'password-1234');
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$mine]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'without invoice.write');
        $this->sendJson('POST', '/api/auth/logout');

        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$theirs]]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, "another company's note is no note of this one");
        self::assertStringContainsString('deliveryNoteIds', (string) $this->client->getResponse()->getContent(), 'refused as a note of no one, not by what drafting it would break later');
        $this->postJson('/api/companies/'.$globex->getId()->toRfc4122().'/invoices/from-delivery-notes', ['deliveryNoteIds' => [$theirs]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        foreach (['delivery_notes', 'invoices'] as $module) {
            $this->sendJson('PUT', $this->companyPath().'/modules/'.$module, ['enabled' => false]);
            self::assertResponseIsSuccessful();
            $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$mine]]);
            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, "with $module switched off");
            $this->sendJson('PUT', $this->companyPath().'/modules/'.$module, ['enabled' => true]);
            self::assertResponseIsSuccessful();
        }
        $this->postJson($this->fromNotesPath(), ['deliveryNoteIds' => [$mine]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /**
     * A note drafted and validated through the API; its id.
     *
     * @param array<string, mixed> $changes
     */
    private function validatedNote(array $changes = []): string
    {
        $this->postJson($this->notePath(), $this->note(['lines' => [['productId' => $this->productId, 'quantity' => '1']], ...$changes]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->notePath($id).'/validate', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    private function persistedValidatedNote(Company $company): string
    {
        $establishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($company->getId())[0];
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $now = new \DateTimeImmutable();
        $note = DeliveryNote::create($company, $establishment, $this->customer('CLI-'.substr($company->getId()->toRfc4122(), -4), $company), new DeliveryNoteHeader(), [new DeliveryNoteLineDetails(null, 'Pièce', '1', $unit, '10', [])], $now);
        $note->validate('BL-'.substr($company->getId()->toRfc4122(), -6), $now, new DeliveryNotePrint('fr', true, true, new PrintSettings('', 'auto', 'auto')), $now);
        $note->releaseEvents();
        $this->em()->persist($note);
        $this->em()->flush();

        return $note->getId()->toRfc4122();
    }

    /** @return list<string> the note's line ids, in order */
    private function lineIds(string $noteId): array
    {
        $ids = $this->em()->getConnection()->fetchFirstColumn('SELECT id FROM delivery_note_line WHERE delivery_note_id = ? ORDER BY position', [$noteId]);

        return array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', $ids);
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function note(array $changes = []): array
    {
        return [...[
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'deliveryDate' => null,
            'deliveryAddressLine1' => null,
            'deliveryAddressLine2' => null,
            'deliveryPostalCode' => null,
            'deliveryCity' => null,
            'deliveryCountryCode' => null,
            'customerReference' => null,
            'remarksPrinted' => null,
            'notesInternal' => null,
            'lines' => [],
        ], ...$changes];
    }

    /**
     * The writable fields of an invoice as it was read.
     *
     * @param array<string, mixed> $invoice
     *
     * @return array<string, mixed>
     */
    private function invoiceBody(array $invoice): array
    {
        return array_intersect_key($invoice, array_flip(['customerId', 'establishmentId', 'supplyDate', 'paymentTermsDays', 'customerReference', 'notesPrinted', 'notesInternal', 'discountAmount', 'documentTaxComponentIds', 'lines']));
    }

    private function customer(string $number, ?Company $company = null): Customer
    {
        $taxRegime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($taxRegime);
        $customer = Customer::create($company ?? $this->company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $taxRegime, [], new \DateTimeImmutable());
        $this->em()->persist($customer);
        $this->em()->flush();

        return $customer;
    }

    private function unit(string $code): \App\Fiscal\Domain\Unit
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function unitId(string $code): string
    {
        return $this->unit($code)->getId()->toRfc4122();
    }

    private function tax(string $code): \App\Fiscal\Domain\TaxComponent
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax;
    }

    private function taxId(string $code): string
    {
        return $this->tax($code)->getId()->toRfc4122();
    }

    private function establishmentId(): string
    {
        return static::getContainer()->get(EstablishmentRepository::class)->ofCompany($this->company->getId())[0]->getId()->toRfc4122();
    }

    private function signedIn(): void
    {
        $this->createSales();
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function createSales(): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, [
            'delivery_note.read', 'delivery_note.write', 'delivery_note.validate',
            'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit',
            'company.read', 'company.settings',
        ], 'member');
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function fromNotesPath(): string
    {
        return $this->companyPath().'/invoices/from-delivery-notes';
    }

    private function invoicePath(?string $id = null): string
    {
        return $this->companyPath().'/invoices'.(null === $id ? '' : '/'.$id);
    }

    private function notePath(?string $id = null): string
    {
        return $this->companyPath().'/delivery-notes'.(null === $id ? '' : '/'.$id);
    }
}
