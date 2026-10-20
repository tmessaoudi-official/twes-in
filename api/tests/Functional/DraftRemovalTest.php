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
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A draft is deleted freely: an invoice, a quote or a delivery note not yet issued, sent or validated goes with its lines
 * and their taxes, and « Restaurer » puts every row back as it was. What was issued, sent or validated is never a
 * draft, and is not found. A draft another record names (the quote it came from) stays, and cancelling it stays offered.
 */
final class DraftRemovalTest extends ApiTestCase
{
    /** Each document's rows: its own, its lines, its lines' taxes and, for an invoice, its document taxes. */
    private const array TABLES = [
        'invoice' => ['invoice_line', 'invoice_line_tax', 'invoice_tax'],
        'quote' => ['quote_line', 'quote_line_tax'],
        'delivery_note' => ['delivery_note_line', 'delivery_note_line_tax'],
    ];

    private Company $company;

    private string $customerId;

    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $standard = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($standard);
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $customer = Customer::create($this->company, 'C-001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $standard, [], $now);
        $product = Product::create($this->company, 'VIS-6', new ProductDetails('Vis 6', null, ProductKind::Goods, '0.500'), $piece, null, [$this->taxId('FODEC'), $this->taxId('TVA19')], $now);
        $this->em()->persist($customer);
        $this->em()->persist($product);
        $this->em()->flush();
        $this->customerId = $customer->getId()->toRfc4122();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testADraftInvoiceGoesWithItsLinesAndTheirTaxesAndComesBackAsItWas(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write']);
        $draft = $this->anInvoice();
        $before = $this->rowsOf('invoice', $draft);
        self::assertCount(2, $before['invoice_line']);
        self::assertCount(4, $before['invoice_line_tax'], 'each line carries its FODEC and its VAT');
        self::assertCount(1, $before['invoice_tax'], 'the stamp');

        $this->postJson($this->path('record-removals'), ['subject' => 'invoice', 'ids' => [$draft]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $removal = $this->json();
        // A draft has no number yet: it reads as the customer it bills.
        self::assertSame(['invoice', [['id' => $draft, 'label' => 'C-001 Carthage Conseil']]], [$removal['subject'], $removal['items']]);
        self::assertSame([], array_filter($this->rowsOf('invoice', $draft)), 'nothing of the draft is left');
        // Put back in steps, a step a table: the draft, then its lines and taxes, then its lines' taxes once their lines stand.
        self::assertSame(
            ['invoice' => 0, 'invoice_line' => 1, 'invoice_line_tax' => 2, 'invoice_tax' => 1],
            array_map(static fn (mixed $step): int => \is_int($step) ? $step : throw new \LogicException('A step reads as an integer.'), $this->em()->getConnection()->fetchAllKeyValue('SELECT DISTINCT table_name, step FROM record_removal_row WHERE removal_id = ? ORDER BY table_name', [$this->stringAt($removal, 'id')])),
        );

        $this->postJson($this->path('record-removals/'.$this->stringAt($removal, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->rowsOf('invoice', $draft));
        self::assertSame(['invoice.deleted', 'invoice.restored'], $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_id = ? AND action IN ('invoice.deleted', 'invoice.restored') ORDER BY action", [$draft]));
    }

    public function testADraftQuoteAndADraftNoteGoAndComeBackAsTheyWere(): void
    {
        $this->signedIn(['quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write']);
        foreach (['quote' => $this->aQuote(), 'delivery_note' => $this->aDeliveryNote()] as $subject => $draft) {
            $before = $this->rowsOf($subject, $draft);
            self::assertNotSame([], $before[self::TABLES[$subject][1]], "$subject: its lines carry their taxes");

            $this->postJson($this->path('record-removals'), ['subject' => $subject, 'ids' => [$draft]]);

            self::assertResponseStatusCodeSame(Response::HTTP_CREATED, $subject);
            self::assertSame([], array_filter($this->rowsOf($subject, $draft)), $subject);
            $this->postJson($this->path('record-removals/'.$this->stringAt($this->json(), 'id').'/restore'), null);
            self::assertResponseIsSuccessful();
            self::assertSame($before, $this->rowsOf($subject, $draft), $subject);
        }
    }

    /** An issued invoice, a sent quote and a validated note are no drafts: each answers as a stranger's would, and stays. */
    public function testOnlyADraftIsDeleted(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write', 'quote.read', 'quote.write', 'delivery_note.read', 'delivery_note.write']);
        $documents = [
            'invoice' => [$this->anInvoice(), "UPDATE invoice SET status = 'issued', number = 'FAC-1', issued_at = now() WHERE id = ?"],
            'quote' => [$this->aQuote(), "UPDATE quote SET status = 'sent', number = 'DEV-1' WHERE id = ?"],
            'delivery_note' => [$this->aDeliveryNote(), "UPDATE delivery_note SET status = 'validated', number = 'BL-1' WHERE id = ?"],
        ];
        foreach ($documents as $subject => [$id, $past]) {
            $this->em()->getConnection()->executeStatement($past, [$id]);
            $before = $this->rowsOf($subject, $id);

            $this->postJson($this->path('record-removals'), ['subject' => $subject, 'ids' => [$id]]);

            self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, $subject);
            self::assertSame($before, $this->rowsOf($subject, $id), $subject);
        }
    }

    /** A draft a quote went into is what that quote is invoiced by: it stays, and the refusal names the quote. */
    public function testADraftAQuoteNamesIsRefusedAndKept(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write', 'quote.read', 'quote.write']);
        $draft = $this->anInvoice();
        $quote = $this->aQuote();
        $this->em()->getConnection()->executeStatement("UPDATE quote SET status = 'accepted', number = 'DEV-1', invoice_id = ? WHERE id = ?", [$draft, $quote]);
        $before = $this->rowsOf('invoice', $draft);

        $this->postJson($this->path('record-removals'), ['subject' => 'invoice', 'ids' => [$draft]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        $records = $this->arrayAt($this->json(), 'records');
        self::assertIsArray($records[0] ?? null);
        self::assertSame([['table' => 'quote', 'column' => 'invoice_id', 'count' => 1]], $records[0]['uses'] ?? null);
        self::assertSame($before, $this->rowsOf('invoice', $draft));
    }

    /** Whoever may read invoices but not write them meets the 404 a stranger would. */
    public function testDeletingADraftTakesTheRightToWriteIt(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write']);
        $draft = $this->anInvoice();
        $this->createUser('reader@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'invoice.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');

        $this->postJson($this->path('record-removals'), ['subject' => 'invoice', 'ids' => [$draft]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM invoice WHERE id = ?', [$draft]));
    }

    /**
     * Every row of the document, by table, each read whole.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rowsOf(string $subject, string $id): array
    {
        $connection = $this->em()->getConnection();
        [$lines, $lineTaxes] = self::TABLES[$subject];
        $rows = [
            $subject => $connection->fetchAllAssociative("SELECT * FROM $subject WHERE id = ?", [$id]),
            $lines => $connection->fetchAllAssociative("SELECT * FROM $lines WHERE {$subject}_id = ? ORDER BY position", [$id]),
            $lineTaxes => $connection->fetchAllAssociative("SELECT t.* FROM $lineTaxes t JOIN $lines l ON l.id = t.line_id WHERE l.{$subject}_id = ? ORDER BY t.id", [$id]),
        ];
        if ('invoice' === $subject) {
            $rows['invoice_tax'] = $connection->fetchAllAssociative('SELECT * FROM invoice_tax WHERE invoice_id = ? ORDER BY id', [$id]);
        }

        return $rows;
    }

    /** A draft invoice of two lines, each taxed, and the stamp. */
    private function anInvoice(): string
    {
        $this->postJson($this->path('invoices'), ['customerId' => $this->customerId, 'establishmentId' => null, 'supplyDate' => null, 'paymentTermsDays' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'documentTaxComponentIds' => [$this->taxId('TIMBRE')->toRfc4122()], 'lines' => [['productId' => $this->productId, 'quantity' => '2'], ['productId' => $this->productId, 'quantity' => '5']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function aQuote(): string
    {
        $this->postJson($this->path('quotes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'customerReference' => null, 'notesPrinted' => null, 'notesInternal' => null, 'discountAmount' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '3']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function aDeliveryNote(): string
    {
        $this->postJson($this->path('delivery-notes'), ['customerId' => $this->customerId, 'establishmentId' => null, 'deliveryDate' => null, 'deliveryAddressLine1' => null, 'deliveryAddressLine2' => null, 'deliveryPostalCode' => null, 'deliveryCity' => null, 'deliveryCountryCode' => null, 'customerReference' => null, 'remarksPrinted' => null, 'notesInternal' => null, 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function taxId(string $code): \Symfony\Component\Uid\Uuid
    {
        $tax = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax->getId();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, ['company.read', ...$permissions], 'member');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}
