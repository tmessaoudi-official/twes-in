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
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
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

        $this->postJson($this->invoicePath($id).'/credit-notes', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame([null, null, null], array_column($this->arrayAt($this->json(), 'lines'), 'sourceDeliveryNoteLineId'), 'a credit note invoices no delivery note');
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
        $note->validate('BL-'.substr($company->getId()->toRfc4122(), -6), $now, $now);
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
            'invoice.read', 'invoice.write', 'invoice.issue',
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
