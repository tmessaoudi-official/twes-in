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
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakePdfRenderer;
use Symfony\Component\HttpFoundation\Response;

/** Quotes through the API: drafted, sent, answered, printed and invoiced. */
final class QuotesTest extends ApiTestCase
{
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';
    private const array WRITER = ['quote.read', 'quote.write', 'invoice.read', 'invoice.write'];

    private Company $company;
    private Customer $customer;
    private string $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->customer = $this->customer('CLI-0001');
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Tour CNC', null, ProductKind::Goods, '1250'), $this->unit('C62'), null, [$this->tax('FODEC')->getId(), $this->tax('TVA19')->getId()], new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testAWriterDraftsAQuoteFiguredAsTheInvoiceWillBeWithoutItsStamp(): void
    {
        $this->signedIn(self::WRITER);

        $this->postJson($this->path(), $this->quote());

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $quote = $this->json();
        self::assertSame(['draft', null, null, null, false], [$quote['status'], $quote['number'], $quote['issueDate'], $quote['validUntil'], $quote['expired']]);
        self::assertSame(['Tour CNC', 'Pose'], array_column($this->arrayAt($quote, 'lines'), 'description'));
        self::assertSame(['1250.0000', '40.0000'], array_column($this->arrayAt($quote, 'lines'), 'unitPriceNet'), 'the product\'s price, a list price absent');
        self::assertSame(['10.000', null], array_column($this->arrayAt($quote, 'lines'), 'discountRate'));
        self::assertSame(['2250.000', '60.000'], array_column($this->arrayAt($quote, 'lines'), 'net'));
        // FODEC 1 % of 2250 enters the VAT base of its line: VAT 19 % of (2272.5 + 60). No stamp: that is the invoice's.
        $taxes = $this->arrayAt($quote, 'taxes');
        self::assertSame([['FODEC', '22.500'], ['TVA19', '443.175']], array_map(null, array_column($taxes, 'code'), array_column($taxes, 'amount')));
        self::assertSame(['2310.000', '0.000', '465.675', '2775.675'], [$quote['subtotalNet'], $quote['documentDiscount'], $quote['totalTax'], $quote['total']]);
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'quote.created'"));

        $this->postJson($this->path(), $this->quote(['discountAmount' => '10']));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame(['10.000', '10.000'], [$this->json()['discountAmount'], $this->json()['documentDiscount']]);
    }

    public function testWhatTheShapeOrTheCompanyRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(self::WRITER);
        $piece = ['description' => 'Pièce', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '10'];

        foreach ([
            ['customerId', ['customerId' => self::ABSENT]],
            ['lines[0].quantity', ['lines' => [[...$piece, 'quantity' => '1.5']]]],
            ['lines[0].discountRate', ['lines' => [[...$piece, 'discountRate' => '101']]]],
            ['lines[0].taxComponentIds', ['lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TIMBRE')]]]]],
            ['discountAmount', ['discountAmount' => '10.0001', 'lines' => [$piece]]],
            ['discountAmount', ['discountAmount' => '11', 'lines' => [$piece]]],
        ] as [$field, $change]) {
            $this->postJson($this->path(), $this->quote($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }
        $this->getJson($this->path());
        self::assertSame([], $this->jsonList());
    }

    public function testSendingNumbersTheQuoteFromItsOwnSeriesAndBindsItsPriceForTheCustomersDays(): void
    {
        $this->signedIn(self::WRITER);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()));
        $number = static fn (int $sequence): string => \sprintf('DEV-%s-%05d', $today->format('Y-m'), $sequence);

        $first = $this->draft();
        $this->postJson($this->path($first).'/send', null);
        self::assertResponseIsSuccessful();
        $quote = $this->json();
        self::assertSame(['sent', $number(1), $today->format('Y-m-d'), $today->modify('+30 days')->format('Y-m-d')], [$quote['status'], $quote['number'], $quote['issueDate'], $quote['validUntil']]);
        self::assertSame('Carthage Conseil', $this->section($quote, 'customerSnapshot')['name']);

        $this->give(SettingLevel::Customer, 'quote.validity_days', 15);
        $second = $this->draft();
        $this->postJson($this->path($second).'/send', null);
        self::assertSame([$number(2), $today->modify('+15 days')->format('Y-m-d')], [$this->json()['number'], $this->json()['validUntil']], 'the customer\'s own validity');

        $this->sendJson('PUT', $this->path($first), $this->quote());
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a sent quote is fixed');
        $this->postJson($this->path($first).'/send', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'and numbered once');
        $this->postJson($this->path($first).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a sent quote is answered, never cancelled');
        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT COUNT(*) FROM numbering_series WHERE document_type = 'quote' AND company_id = ?", [$this->company->getId()->toRfc4122()]));
    }

    public function testTheCustomersAnswerIsRecordedAndARefusalsWordsStayOutOfTheAudit(): void
    {
        $this->signedIn(self::WRITER);
        $accepted = $this->sent();
        $refused = $this->sent();

        $this->postJson($this->path($accepted).'/accept', ['answeredOn' => null]);
        self::assertResponseIsSuccessful();
        self::assertSame(['accepted', new \DateTimeImmutable('now', new \DateTimeZone('Africa/Tunis'))->format('Y-m-d')], [$this->json()['status'], $this->json()['answeredOn']]);

        $this->postJson($this->path($refused).'/refuse', ['answeredOn' => '2000-01-01', 'refusalReason' => 'Trop cher.']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'before its issue day');
        $this->postJson($this->path($refused).'/refuse', ['refusalReason' => 'Trop cher.']);
        self::assertResponseIsSuccessful();
        self::assertSame(['refused', 'Trop cher.'], [$this->json()['status'], $this->json()['refusalReason']]);
        $audit = $this->text("SELECT changes::text FROM audit_log WHERE action = 'quote.refused'");
        self::assertStringContainsString('reason', $audit);
        self::assertStringNotContainsString('Trop cher', $audit);

        $this->postJson($this->path($refused).'/accept', ['answeredOn' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an answered quote is answered once');
        $draft = $this->draft();
        $this->postJson($this->path($draft).'/accept', ['answeredOn' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft is sent before it is answered');
        $this->postJson($this->path($draft).'/cancel', null);
        self::assertSame('cancelled', $this->json()['status']);
    }

    public function testAnAcceptedQuoteIsInvoicedOnceIntoADraftThatTakesTheInvoicesStamp(): void
    {
        $this->signedIn(self::WRITER);
        $id = $this->sent(['customerReference' => 'RFQ-12', 'notesPrinted' => 'Livraison comprise.', 'discountAmount' => '10']);
        $this->postJson($this->path($id).'/invoice', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'only an accepted quote is invoiced');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'), 'and a refusal drafts nothing');

        $this->postJson($this->path($id).'/accept', ['answeredOn' => null]);
        $this->postJson($this->path($id).'/invoice', null);

        self::assertResponseIsSuccessful();
        $invoiceId = $this->stringAt($this->json(), 'invoiceId');
        $this->getJson($this->companyPath().'/invoices/'.$invoiceId);
        $invoice = $this->json();
        self::assertSame(['draft', $this->customer->getId()->toRfc4122(), 'RFQ-12', 'Livraison comprise.', '10.000'], [$invoice['status'], $invoice['customerId'], $invoice['customerReference'], $invoice['notesPrinted'], $invoice['discountAmount']]);
        self::assertSame(['Tour CNC', 'Pose'], array_column($this->arrayAt($invoice, 'lines'), 'description'));
        self::assertSame(['10.000', null], array_column($this->arrayAt($invoice, 'lines'), 'discountRate'));
        self::assertContains($this->taxId('TIMBRE'), $this->arrayAt($invoice, 'documentTaxComponentIds'), 'the invoice takes its stamp as any draft does');
        self::assertStringContainsString($id, $this->text("SELECT changes::text FROM audit_log WHERE action = 'invoice.created'"));

        $this->postJson($this->path($id).'/invoice', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'invoiced once');
        $this->postJson($this->companyPath().'/invoices/'.$invoiceId.'/cancel', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->path($id).'/invoice', null);
        self::assertResponseIsSuccessful();
        self::assertNotSame($invoiceId, $this->json()['invoiceId'], 'again once that draft is cancelled');
    }

    public function testInvoicingAQuoteAsksForWritingInvoicesAsWell(): void
    {
        $this->signedIn(['quote.read', 'quote.write']);
        $id = $this->sent();
        $this->postJson($this->path($id).'/accept', ['answeredOn' => null]);

        $this->postJson($this->path($id).'/invoice', null);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));
    }

    public function testQuotingNeedsOnlyCustomersWhileInvoicingAQuoteNeedsInvoicesOn(): void
    {
        $this->signedIn([...self::WRITER, 'company.read', 'company.settings']);
        $id = $this->sent();
        $this->postJson($this->path($id).'/accept', ['answeredOn' => null]);
        $this->sendJson('PUT', $this->companyPath().'/modules/invoices', ['enabled' => false]);
        self::assertResponseIsSuccessful('quotes do not hold invoices on');

        $this->getJson($this->path($id));
        self::assertResponseIsSuccessful('the quote is still read');
        $this->postJson($this->path($id).'/invoice', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'nowhere to draft the invoice');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'));

        $this->sendJson('PUT', $this->companyPath().'/modules/invoices', ['enabled' => true]);
        $this->postJson($this->path($id).'/invoice', null);
        self::assertResponseIsSuccessful();
    }

    public function testTheFormReadsItsOptionsAndPicksWithQuoteReadAloneAndNoProductsWhenTheyAreOff(): void
    {
        $this->signedIn(['quote.read', 'company.read', 'company.settings']);

        $this->getJson($this->companyPath().'/quote-options');
        self::assertResponseIsSuccessful();
        self::assertSame(['TND', 3], [$this->json()['currency'], $this->json()['currencyScale']]);
        self::assertContains('TVA19', array_column($this->arrayAt($this->json(), 'taxes'), 'code'));
        $this->getJson($this->companyPath().'/quote-options/customers?q=CLI-0001');
        self::assertSame([$this->customer->getId()->toRfc4122()], array_column($this->jsonList(), 'id'));
        $this->getJson($this->companyPath().'/quote-options/products?q=ART-001');
        self::assertSame([$this->productId], array_column($this->jsonList(), 'id'));

        $this->sendJson('PUT', $this->companyPath().'/modules/invoices', ['enabled' => false]);
        $this->sendJson('PUT', $this->companyPath().'/modules/inventory', ['enabled' => false]);
        $this->sendJson('PUT', $this->companyPath().'/modules/delivery_notes', ['enabled' => false]);
        $this->sendJson('PUT', $this->companyPath().'/modules/price_lists', ['enabled' => false]);
        $this->sendJson('PUT', $this->companyPath().'/modules/scanning', ['enabled' => false]);
        $this->sendJson('PUT', $this->companyPath().'/modules/products', ['enabled' => false]);
        self::assertResponseIsSuccessful('nothing a quote needs holds products on');
        $this->getJson($this->companyPath().'/quote-options/products?q=ART-001');
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList(), 'products off: every line is written by hand');

        $company = $this->em()->find(Company::class, $this->company->getId()) ?? self::fail('the company is gone');
        $this->createUser('books@twes.local', 'password-1234', $company, ['invoice.read'], 'bookkeeper');
        $this->login('books@twes.local', 'password-1234');
        $this->getJson($this->companyPath().'/quote-options');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'reading invoices is not reading quotes');
    }

    public function testAReaderOnlyReadsAndAnotherCompanysQuoteIsNotThere(): void
    {
        $other = $this->createCompany('Globex');
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['quote.read'], 'reader');
        $this->createUser('stranger@twes.local', 'password-1234', $other, ['*'], 'owner');
        $this->signedIn(self::WRITER);
        $id = $this->draft();

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($id));
        self::assertResponseIsSuccessful();
        $this->postJson($this->path($id).'/send', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'sending needs quote.write');

        $this->login('stranger@twes.local', 'password-1234');
        $this->getJson('/api/companies/'.$other->getId()->toRfc4122().'/quotes/'.$id);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testTheListSearchesNarrowsAndCountsByStatus(): void
    {
        $this->signedIn(self::WRITER);
        $sent = $this->sent(['customerReference' => 'RFQ-ALPHA']);
        $this->draft();
        $this->draft();

        $this->getJson($this->path().'?status[]=draft');
        self::assertCount(2, $this->jsonList());
        $this->getJson($this->path().'?q=alpha');
        self::assertSame([$sent], array_column($this->jsonList(), 'id'));
        $this->getJson($this->companyPath().'/quote-status-counts');
        self::assertSame(['all' => 3, 'statuses' => ['draft' => 2, 'sent' => 1, 'accepted' => 0, 'refused' => 0, 'cancelled' => 0]], $this->json());
    }

    public function testTheSignedCopyIsAttachedAndTheQuotePrints(): void
    {
        $this->signedIn(self::WRITER);
        $id = $this->sent();

        $this->uploadFile($this->path($id).'/attachments', 'devis-signé.pdf', "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $attachment = $this->stringAt($this->json(), 'id');
        $this->getJson($this->path($id));
        self::assertSame(1, $this->json()['attachmentCount']);
        $this->getJson($this->path($id).'/attachments/'.$attachment.'/content');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        $html = (string) end(static::getContainer()->get(FakePdfRenderer::class)->rendered);
        self::assertStringContainsString('Bon pour accord', $html);
        self::assertStringContainsString('jusqu’au', $html);

        $this->sendJson('DELETE', $this->path($id).'/attachments/'.$attachment);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT, 'a file attached by mistake comes off');
    }

    /** A quote's line discounted by an amount says so, is refused as an invoice's is, and its invoice keeps it. */
    public function testALineDiscountByAnAmountIsKeptIntoTheInvoice(): void
    {
        $this->signedIn(self::WRITER);
        $pose = ['description' => 'Pose', 'quantity' => '1.5', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA19')]];
        $this->postJson($this->path(), $this->quote(['lines' => [[...$pose, 'discountRate' => '5', 'discountAmount' => '5']]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('lines[0].discountAmount', (string) $this->client->getResponse()->getContent());
        $this->postJson($this->path(), $this->quote(['lines' => [[...$pose, 'discountAmount' => '60.001']]]));
        self::assertStringContainsString('lines[0].discountAmount', (string) $this->client->getResponse()->getContent());

        $id = $this->sent(['lines' => [[...$pose, 'discountAmount' => '12']]]);
        $this->getJson($this->path($id));
        self::assertSame([['12.000', null, '48.000']], array_map(null, array_column($this->arrayAt($this->json(), 'lines'), 'discountAmount'), array_column($this->arrayAt($this->json(), 'lines'), 'discountRate'), array_column($this->arrayAt($this->json(), 'lines'), 'net')));

        $this->postJson($this->path($id).'/accept', ['answeredOn' => null]);
        $this->postJson($this->path($id).'/invoice', null);
        $this->getJson($this->companyPath().'/invoices/'.$this->stringAt($this->json(), 'invoiceId'));
        self::assertSame([['12.000', '48.000']], array_map(null, array_column($this->arrayAt($this->json(), 'lines'), 'discountAmount'), array_column($this->arrayAt($this->json(), 'lines'), 'net')));
    }

    /** @param array<string, mixed> $changes */
    private function sent(array $changes = []): string
    {
        $id = $this->draft($changes);
        $this->postJson($this->path($id).'/send', null);
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @param array<string, mixed> $changes */
    private function draft(array $changes = []): string
    {
        $this->postJson($this->path(), $this->quote($changes));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function quote(array $changes = []): array
    {
        return [...[
            'customerId' => $this->customer->getId()->toRfc4122(),
            'establishmentId' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'lines' => [
                ['productId' => $this->productId, 'quantity' => '2', 'discountRate' => '10'],
                ['description' => 'Pose', 'quantity' => '1.5', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId('TVA19')]],
            ],
        ], ...$changes];
    }

    private function give(SettingLevel $level, string $key, int $value): void
    {
        // The test client's requests reboot the kernel: the company is the one its entity manager holds now.
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);
        $context = SettingLevel::Customer === $level ? new SettingContext($company, customerId: $this->customer->getId()) : new SettingContext($company);
        static::getContainer()->get(ChangeSettings::class)->change($context, $key, $level, $value, null);
    }

    private function customer(string $number): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
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

    private function path(?string $id = null): string
    {
        return $this->companyPath().'/quotes'.(null === $id ? '' : '/'.$id);
    }

    /** One text a plain SQL read answers, such as an audit row's changes. */
    private function text(string $sql): string
    {
        $value = $this->em()->getConnection()->fetchOne($sql);
        self::assertIsString($value);

        return $value;
    }
}
