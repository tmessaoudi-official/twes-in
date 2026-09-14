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
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\EstablishmentRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final class InvoicesTest extends ApiTestCase
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
        $this->customerId = $this->customer('CLI-0001', 'standard', [$this->tax('RS1')->getId()], '5')->getId()->toRfc4122();
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $this->unit('C62'), null, [$this->tax('FODEC')->getId(), $this->tax('TVA19')->getId()], new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    public function testTheOptionsSayWhatTheInvoiceFormAsksFor(): void
    {
        $this->signedIn(['invoice.read']);

        $this->getJson($this->companyPath().'/invoice-options');

        self::assertResponseIsSuccessful();
        $options = $this->json();
        self::assertSame(['TND', 3], [$options['currency'], $options['currencyScale']]);
        self::assertSame(['000'], array_column($this->arrayAt($options, 'establishments'), 'code'));
        $customers = $this->arrayAt($options, 'customers');
        self::assertSame(['CLI-0001'], array_column($customers, 'number'));
        self::assertSame(['5.000'], array_column($customers, 'defaultDiscountRate'));
        self::assertSame([[$this->taxId('RS1')]], array_column($customers, 'defaultTaxComponentIds'));
        self::assertSame(['ART-001'], array_column($this->arrayAt($options, 'products'), 'reference'));
        $taxes = array_column($this->arrayAt($options, 'taxes'), null, 'code');
        self::assertIsArray($taxes['TVA19']);
        self::assertIsArray($taxes['TIMBRE']);
        self::assertIsArray($taxes['RS1']);
        self::assertSame(['percentage_line', 'vat', '19.000', null, null, true], [$taxes['TVA19']['kind'], $taxes['TVA19']['family'], $taxes['TVA19']['rate'], $taxes['TVA19']['amount'], $taxes['TVA19']['threshold'], $taxes['TVA19']['isDefault']]);
        self::assertSame(['fixed_document', 'stamp', null, '1.000', null, true], [$taxes['TIMBRE']['kind'], $taxes['TIMBRE']['family'], $taxes['TIMBRE']['rate'], $taxes['TIMBRE']['amount'], $taxes['TIMBRE']['threshold'], $taxes['TIMBRE']['isDefault']]);
        self::assertSame(['withholding_total', '1.000', '1000.000', false], [$taxes['RS1']['kind'], $taxes['RS1']['rate'], $taxes['RS1']['threshold'], $taxes['RS1']['isDefault']]);
    }

    public function testAWriterDraftsAnInvoiceWhoseFiguresApplyItsDiscountsItsStampAndItsWithholding(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write']);

        $this->postJson($this->path(), $this->invoice([
            'supplyDate' => '2026-09-10',
            'paymentTermsDays' => 45,
            'customerReference' => 'PO-77',
            'notesPrinted' => 'Merci',
            'lines' => [
                ['description' => 'Conseil', 'quantity' => '2', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '500', 'discountRate' => '10', 'taxComponentIds' => [$this->taxId('TVA19')]],
                ['productId' => $this->productId, 'quantity' => '1'],
            ],
            'discountAmount' => '1125',
        ]));

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $invoice = $this->json();
        self::assertSame(['invoice', 'draft', null, null, null], [$invoice['type'], $invoice['status'], $invoice['number'], $invoice['issueDate'], $invoice['correctsInvoiceId']]);
        self::assertSame([$this->establishmentId(), $this->customerId], [$invoice['establishmentId'], $invoice['customerId']]);
        self::assertSame(['2026-09-10', 45, 'PO-77', 'Merci', null, '1125.000'], [$invoice['supplyDate'], $invoice['paymentTermsDays'], $invoice['customerReference'], $invoice['notesPrinted'], $invoice['notesInternal'], $invoice['discountAmount']]);
        self::assertSame([$this->taxId('TIMBRE'), $this->taxId('RS1')], $invoice['documentTaxComponentIds'], 'left out, the company\'s stamp and the customer\'s withholding');
        $lines = $this->arrayAt($invoice, 'lines');
        self::assertSame(['10.000', null], array_column($lines, 'discountRate'));
        self::assertSame(['Conseil', 'Portable 14"'], array_column($lines, 'description'));
        self::assertSame([[$this->taxId('TVA19')], [$this->taxId('FODEC'), $this->taxId('TVA19')]], array_column($lines, 'taxComponentIds'));
        self::assertSame(['900.000', '1250.000'], array_column($lines, 'net'));
        // 2150 of lines less 1125 is 1025. The discount is spread pro rata over the two tax groups, 900:1250, the
        // remainder to the largest: 470.930 and 654.070 off. FODEC is 1 % of 595.930, 5.959, and enters its VAT base:
        // VAT 19 % of 429.070 + 595.930 + 5.959 is 195.882. 1025 + 201.841 = 1226.841 reaches the RS1 threshold: 1 %
        // of it, 12.268, is withheld from what is due, and the stamp adds 1.000 to the total.
        self::assertSame(['2150.000', '1125.000', '1025.000'], [$invoice['subtotalNet'], $invoice['documentDiscount'], $invoice['totalNet']]);
        $taxes = $this->arrayAt($invoice, 'taxes');
        self::assertSame([['TVA19', '195.882'], ['FODEC', '5.959']], array_map(null, array_column($taxes, 'code'), array_column($taxes, 'amount')), 'in the order they first appear');
        self::assertSame([['TIMBRE', '1.000']], array_map(static fn (mixed $charge): array => \is_array($charge) ? [$charge['code'], $charge['amount']] : [], $this->arrayAt($invoice, 'fixedTaxes')));
        self::assertSame(['201.841', '1227.841'], [$invoice['totalTax'], $invoice['total']]);
        self::assertSame([['RS1', '1226.841', '12.268']], array_map(static fn (mixed $held): array => \is_array($held) ? [$held['code'], $held['base'], $held['amount']] : [], $this->arrayAt($invoice, 'withholdings')));
        self::assertSame('1215.573', $invoice['amountDue']);

        $this->getJson($this->path($this->stringAt($invoice, 'id')));
        self::assertResponseIsSuccessful();
        self::assertSame('1215.573', $this->json()['amountDue'], 'the stored draft totals the same');
        $this->getJson($this->path());
        self::assertCount(1, $this->jsonList());
        self::assertSame('[]', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.created'"));
    }

    public function testWhatTheShapeOrTheCompanyRefusesAnswersUnprocessableNamingTheField(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write']);
        $piece = ['description' => 'Pièce', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '10'];
        $exempt = $this->customer('CLI-0002', 'exempt')->getId()->toRfc4122();

        foreach ([
            ['customerId', ['customerId' => self::ABSENT]],
            ['establishmentId', ['establishmentId' => self::ABSENT]],
            ['supplyDate', ['supplyDate' => '10/09/2026']],
            ['paymentTermsDays', ['paymentTermsDays' => 400]],
            ['discountAmount', ['discountAmount' => '-1']],
            ['discountAmount', ['lines' => [$piece], 'discountAmount' => '10.001']],
            ['lines[0].discountRate', ['lines' => [[...$piece, 'discountRate' => '101']]]],
            ['lines[0].taxComponentIds', ['lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TIMBRE')]]]]],
            ['lines[0].taxComponentIds', ['customerId' => $exempt, 'lines' => [[...$piece, 'taxComponentIds' => [$this->taxId('TVA19')]]]]],
            ['documentTaxComponentIds', ['documentTaxComponentIds' => [$this->taxId('TVA19')]]],
            ['documentTaxComponentIds', ['documentTaxComponentIds' => [self::ABSENT]]],
        ] as [$field, $change]) {
            $this->postJson($this->path(), $this->invoice($change));
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, $field);
            self::assertStringContainsString($field, (string) $this->client->getResponse()->getContent());
        }
        $this->postJson($this->path(), $this->invoice(['documentTaxComponentIds' => ['first' => $this->taxId('TIMBRE')]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a JSON object is not a list');
    }

    public function testARevisionIsAuditedAndADraftIsCancelledOnceAndThenFixed(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write']);
        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), $this->invoice(['customerReference' => 'PO-78', 'documentTaxComponentIds' => [], 'lines' => [['productId' => $this->productId, 'quantity' => '3', 'discountRate' => '5']]]));

        self::assertResponseIsSuccessful();
        $invoice = $this->json();
        self::assertSame(['PO-78', [], ['5.000']], [$invoice['customerReference'], $invoice['documentTaxComponentIds'], array_column($this->arrayAt($invoice, 'lines'), 'discountRate')]);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.revised'");
        self::assertIsString($changes);
        self::assertSame(['fields' => ['customerReference', 'documentTaxComponentIds', 'lines']], json_decode($changes, true));

        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame('cancelled', $this->json()['status']);
        $this->sendJson('PUT', $this->path($id), $this->invoice());
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a cancelled invoice is no longer revised');
        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an invoice is cancelled once');

        $this->sendJson('PUT', $this->path(self::ABSENT), $this->invoice());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(self::ABSENT).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnIssuerNumbersAnInvoiceWithoutGapsAndItThenAnswersWhatIssuingWrote(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write', 'invoice.issue']);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $number = static fn (int $sequence): string => \sprintf('FAC-%s-%05d', substr($today, 0, 4), $sequence);
        $inDays = static fn (int $days): string => new \DateTimeImmutable($today)->modify("+$days days")->format('Y-m-d');
        $this->postJson($this->path(), $this->invoice(['paymentTermsDays' => 45, 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $id = $this->stringAt($this->json(), 'id');
        $draft = $this->json();

        $this->postJson($this->path($id).'/issue', null);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        $invoice = $this->json();
        self::assertSame(['issued', $number(1), $today, $inDays(45), 45, 'fr'], [$invoice['status'], $invoice['number'], $invoice['issueDate'], $invoice['dueDate'], $invoice['paymentTermsDays'], $invoice['language']]);
        self::assertSame([$draft['total'], $draft['amountDue'], '0.000', '0.000'], [$invoice['total'], $invoice['amountDue'], $invoice['amountPaid'], $invoice['amountCredited']]);
        self::assertSame([[], null], [$invoice['mentions'], $invoice['footer']], 'a Tunisian standard customer of a standard company prints no mention');
        $snapshot = $this->arrayAt($invoice, 'customerSnapshot');
        self::assertSame(['CLI-0001', 'Carthage Conseil', 'standard'], [$snapshot['number'], $snapshot['name'], $snapshot['taxRegimeCode']]);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.issued'");
        self::assertIsString($changes);
        self::assertSame(['number' => $number(1)], json_decode($changes, true));

        $this->em()->getConnection()->executeStatement('UPDATE invoice_line SET unit_price_net = 1 WHERE invoice_id = ?', [$id]);
        $this->getJson($this->path($id));
        self::assertSame([$draft['total'], $draft['amountDue'], $draft['taxes']], [$this->json()['total'], $this->json()['amountDue'], $this->json()['taxes']], 'an issued invoice is never recomputed');
        self::assertSame(array_column($this->arrayAt($draft, 'lines'), 'net'), array_column($this->arrayAt($this->json(), 'lines'), 'net'));

        $this->sendJson('PUT', $this->path($id), $this->invoice());
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an issued invoice is no longer revised');
        $this->postJson($this->path($id).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an issued invoice is corrected by a credit note, never cancelled');
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'an invoice is numbered once');

        $this->postJson($this->path(), $this->invoice());
        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'an invoice without a line is not issued');
        self::assertStringContainsString('lines', (string) $this->client->getResponse()->getContent());

        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '2']]]));
        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([$number(2), 30, $inDays(30)], [$this->json()['number'], $this->json()['paymentTermsDays'], $this->json()['dueDate']], 'terms left out are the customer\'s at issue, and a refused issue gave its number back');

        $this->postJson($this->path(self::ABSENT).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAWriterDraftsButDoesNotIssue(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write']);
        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $id = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path($id).'/issue', null);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'issuing needs invoice.issue');
        $this->getJson($this->path($id));
        self::assertSame(['draft', null, null, null], [$this->json()['status'], $this->json()['number'], $this->json()['dueDate'], $this->json()['customerSnapshot']]);
    }

    public function testADraftPrintsOnRequestAndAnIssuedInvoicePrintsAsItWasIssued(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write', 'invoice.issue']);
        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $id = $this->stringAt($this->json(), 'id');

        $this->client->request('GET', $this->path($id).'/pdf');

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('application/pdf', $response->headers->get('content-type'));
        self::assertStringContainsString('invoice-'.$id.'.pdf', (string) $response->headers->get('content-disposition'));
        $draft = (string) $response->getContent();
        self::assertStringStartsWith('%PDF-', $draft);
        self::assertStringContainsString('BROUILLON', $draft);
        self::assertStringContainsString("1\u{a0}250,000", $draft);
        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM file'), 'a draft is never stored');

        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseIsSuccessful();
        $number = $this->stringAt($this->json(), 'number');
        $stored = $this->em()->getConnection()->fetchAssociative('SELECT f.original_name, f.mime, f.sha256 FROM file f JOIN invoice i ON i.pdf_file_id = f.id WHERE i.id = ?', [$id]);
        self::assertIsArray($stored, 'issuing stores the PDF as it was issued');
        self::assertSame([$number.'.pdf', 'application/pdf'], [$stored['original_name'], $stored['mime']]);

        $this->client->request('GET', $this->path($id).'/pdf');

        self::assertResponseIsSuccessful();
        $issued = (string) $this->client->getResponse()->getContent();
        self::assertSame($stored['sha256'], hash('sha256', $issued), 'the download is the stored file');
        self::assertStringContainsString($number, $issued);
        self::assertStringContainsString('Carthage Conseil', $issued);
        self::assertStringContainsString('Facture', $issued);
        self::assertStringNotContainsString('BROUILLON', $issued);

        $this->client->request('GET', $this->path(self::ABSENT).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('POST', '/api/auth/logout');
        $this->client->request('GET', $this->path($id).'/pdf');
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testAPaymentHolderRecordsAndDeletesPaymentsAndTheInvoiceSaysWhatIsStillDue(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write', 'invoice.issue', 'payment.write']);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $id = $this->issuedInvoice();
        $due = $this->stringAt($this->json(), 'amountDue');
        self::assertIsNumeric($due);
        $payments = $this->path($id).'/payments';

        $this->postJson($payments, ['date' => $today, 'amount' => '100', 'method' => 'transfer', 'reference' => 'VIR-1', 'notes' => null]);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $first = $this->json();
        self::assertSame([$today, '100.000', 'transfer', 'VIR-1', null], [$first['date'], $first['amount'], $first['method'], $first['reference'], $first['notes']]);
        $firstId = $this->stringAt($first, 'id');
        $this->getJson($this->path($id));
        $remaining = bcsub($due, '100', 3);
        self::assertSame(['partially_paid', '100.000', $remaining], [$this->json()['status'], $this->json()['amountPaid'], $this->json()['amountDue']]);
        self::assertSame([$firstId], array_column($this->arrayAt($this->json(), 'payments'), 'id'));

        $tomorrow = new \DateTimeImmutable($today)->modify('+1 day')->format('Y-m-d');
        $yesterday = new \DateTimeImmutable($today)->modify('-1 day')->format('Y-m-d');
        foreach ([
            'amount' => ['amount' => bcadd($remaining, '0.001', 3)],
            'amount ' => ['amount' => '0'],
            'amount  ' => ['amount' => '1.0001'],
            'date' => ['date' => $tomorrow],
            'date ' => ['date' => $yesterday],
            'method' => ['method' => 'bitcoin'],
        ] as $field => $change) {
            $this->postJson($payments, [...['date' => $today, 'amount' => '1', 'method' => 'cash', 'reference' => null, 'notes' => null], ...$change]);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, json_encode($change, \JSON_THROW_ON_ERROR));
            self::assertStringContainsString(trim($field), (string) $this->client->getResponse()->getContent());
        }

        $this->postJson($payments, ['date' => $today, 'amount' => $remaining, 'method' => 'cash', 'reference' => null, 'notes' => 'Au comptoir']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson($this->path($id));
        self::assertSame(['paid', $due, '0.000'], [$this->json()['status'], $this->json()['amountPaid'], $this->json()['amountDue']]);
        self::assertEquals(2, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'payment.recorded'"));

        $this->sendJson('DELETE', $payments.'/'.$firstId);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path($id));
        self::assertSame(['partially_paid', $remaining, '100.000'], [$this->json()['status'], $this->json()['amountPaid'], $this->json()['amountDue']]);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'payment.deleted'");
        self::assertIsString($changes);
        self::assertEquals(['paymentId' => $firstId, 'date' => $today, 'amount' => '100.000', 'method' => 'transfer'], json_decode($changes, true), 'jsonb keeps the keys, not their order');

        $this->sendJson('DELETE', $payments.'/'.$firstId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a payment is deleted once');
        $this->postJson($this->path(self::ABSENT).'/payments', ['date' => $today, 'amount' => '1', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/payments', ['date' => $today, 'amount' => '1', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft is not paid');
    }

    public function testACreditNoteTakesWhatItCorrectsOffWhatItsInvoiceStillHasDue(): void
    {
        $other = $this->customer('CLI-0002', 'standard')->getId()->toRfc4122();
        $this->signedIn(['invoice.read', 'invoice.write', 'invoice.issue', 'payment.write']);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $number = static fn (int $sequence): string => \sprintf('AV-%s-%05d', substr($today, 0, 4), $sequence);
        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '2']]]));
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($id).'/issue', null);
        $invoice = $this->json();
        $due = $this->stringAt($invoice, 'amountDue');
        self::assertIsNumeric($due);

        $this->postJson($this->path($id).'/credit-notes', null);

        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $credit = $this->json();
        $creditId = $this->stringAt($credit, 'id');
        self::assertSame(['credit_note', 'draft', null, $id, $this->customerId], [$credit['type'], $credit['status'], $credit['number'], $credit['correctsInvoiceId'], $credit['customerId']]);
        self::assertSame(['-'.$this->stringAt($invoice, 'total'), $invoice['documentTaxComponentIds'], ['2.000']], [$this->stringAt($credit, 'total'), $credit['documentTaxComponentIds'], array_column($this->arrayAt($credit, 'lines'), 'quantity')], 'a credit note starts as the whole invoice, negative');
        self::assertEquals(1, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM audit_log WHERE action = 'invoice.created' AND entity_id = ?", [$creditId]));

        $this->sendJson('PUT', $this->path($creditId), $this->invoice(['customerId' => $other, 'lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a credit note goes to its invoice\'s customer');
        self::assertStringContainsString('customerId', (string) $this->client->getResponse()->getContent());
        $this->sendJson('PUT', $this->path($creditId), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        self::assertResponseIsSuccessful();

        $this->postJson($this->path($creditId).'/issue', null);

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame(['issued', $number(1)], [$this->json()['status'], $this->json()['number']]);
        $credited = $this->stringAt($this->json(), 'amountDue');
        self::assertStringStartsWith('-', $credited);
        $credited = ltrim($credited, '-');
        self::assertIsNumeric($credited);
        $remaining = bcsub($due, $credited, 3);
        $this->getJson($this->path($id));
        self::assertSame(['partially_paid', '0.000', $credited, $remaining], [$this->json()['status'], $this->json()['amountPaid'], $this->json()['amountCredited'], $this->json()['amountDue']]);
        $changes = $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'invoice.credited'");
        self::assertIsString($changes);
        self::assertEquals(['creditNoteId' => $creditId, 'number' => $number(1), 'amount' => $credited], json_decode($changes, true));

        $this->postJson($this->path($id).'/credit-notes', null);
        $secondId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($secondId).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the whole invoice again is more than it still has due');
        self::assertStringContainsString('amountDue', (string) $this->client->getResponse()->getContent());
        $this->getJson($this->path($id));
        self::assertSame([$credited, $remaining], [$this->json()['amountCredited'], $this->json()['amountDue']]);

        $this->sendJson('PUT', $this->path($secondId), $this->invoice(['documentTaxComponentIds' => [], 'lines' => [['description' => 'Geste commercial', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '100', 'taxComponentIds' => []]]]));
        self::assertResponseIsSuccessful();
        $this->postJson($this->path($secondId).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertSame([$number(2), '-100.000'], [$this->json()['number'], $this->json()['amountDue']], 'the refused issue gave its number back');
        $remaining = bcsub($remaining, '100', 3);
        $this->postJson($this->path($id).'/payments', ['date' => $today, 'amount' => $remaining, 'method' => 'transfer']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson($this->path($id));
        self::assertSame(['paid', '0.000', bcadd($credited, '100', 3)], [$this->json()['status'], $this->json()['amountDue'], $this->json()['amountCredited']], 'credits and payments settle an invoice together');

        $this->postJson($this->path($creditId).'/credit-notes', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a credit note is not credited');
        $this->postJson($this->path($creditId).'/payments', ['date' => $today, 'amount' => '1', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a credit note is not paid');
        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $this->postJson($this->path($this->stringAt($this->json(), 'id')).'/credit-notes', null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'a draft is not credited');
        $this->postJson($this->path(self::ABSENT).'/credit-notes', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testPaymentsNeedPaymentWriteAndStayInTheirCompany(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write', 'invoice.issue']);
        $today = new \DateTimeImmutable('now', new \DateTimeZone($this->company->getTimezone()))->format('Y-m-d');
        $id = $this->issuedInvoice();

        $this->postJson($this->path($id).'/payments', ['date' => $today, 'amount' => '1', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'recording a payment needs payment.write');
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM payment'));

        $globex = $this->createCompany('Globex');
        $this->createUser('globex@twes.local', 'password-1234', $globex, ['invoice.read', 'payment.write'], 'member');
        $this->sendJson('POST', '/api/auth/logout');
        $this->login('globex@twes.local', 'password-1234');
        $this->postJson('/api/companies/'.$globex->getId()->toRfc4122().'/invoices/'.$id.'/payments', ['date' => $today, 'amount' => '1', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company\'s invoice');
        $this->postJson($this->path($id).'/payments', ['date' => $today, 'amount' => '1', 'method' => 'cash']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'a company the user is not a member of');
    }

    public function testAReaderOnlyReadsAndAnotherCompanysInvoiceIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        static::getContainer()->get(ProvisionCompany::class)->handle($globex);
        $theirEstablishment = static::getContainer()->get(EstablishmentRepository::class)->ofCompany($globex->getId())[0];
        $theirs = Invoice::create($globex, $theirEstablishment, $this->customer('CLI-0001', 'standard', company: $globex), new InvoiceHeader(), [], [], new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['invoice.read'], 'reader');
        $this->signedIn(['invoice.read', 'invoice.write']);
        $this->postJson($this->path(), $this->invoice(['documentTaxComponentIds' => []]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, 'a draft may have no line yet');
        $mine = $this->stringAt($this->json(), 'id');
        self::assertSame(['0.000', '0.000', '0.000', []], [$this->json()['total'], $this->json()['subtotalNet'], $this->json()['amountDue'], $this->json()['taxes']]);

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path($mine));
        self::assertResponseIsSuccessful();
        $this->getJson($this->companyPath().'/invoice-options');
        self::assertResponseIsSuccessful();
        $this->sendJson('PUT', $this->path($mine), $this->invoice());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), $this->invoice());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path($mine).'/cancel', null);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->getJson($this->path($theirs->getId()->toRfc4122()));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/invoices');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('not-a-uuid'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('POST', '/api/auth/logout');
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_UNAUTHORIZED);
    }

    public function testSwitchedOffTheModuleAnswersNotFoundAndKeepsItsInvoices(): void
    {
        $this->signedIn(['invoice.read', 'invoice.write', 'company.read', 'company.settings']);
        $this->postJson($this->path(), $this->invoice());
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->sendJson('PUT', $this->companyPath().'/modules/invoices', ['enabled' => false]);
        self::assertResponseIsSuccessful();
        $this->getJson($this->path());
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->companyPath().'/invoice-options');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('PUT', $this->companyPath().'/modules/invoices', ['enabled' => true]);
        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->jsonList());
    }

    /** Drafts and issues a one-line invoice; the response left to read is the issued invoice. */
    private function issuedInvoice(): string
    {
        $this->postJson($this->path(), $this->invoice(['lines' => [['productId' => $this->productId, 'quantity' => '1']]]));
        $id = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path($id).'/issue', null);
        self::assertResponseStatusCodeSame(Response::HTTP_OK);

        return $id;
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function invoice(array $changes = []): array
    {
        return [...[
            'customerId' => $this->customerId,
            'establishmentId' => null,
            'supplyDate' => null,
            'paymentTermsDays' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'documentTaxComponentIds' => null,
            'lines' => [],
        ], ...$changes];
    }

    /** @param list<Uuid> $defaultTaxes */
    private function customer(string $number, string $regime, array $defaultTaxes = [], ?string $defaultDiscountRate = null, ?Company $company = null): Customer
    {
        $taxRegime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', $regime);
        self::assertNotNull($taxRegime);
        $profile = new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', defaultDiscountRate: $defaultDiscountRate);
        $customer = Customer::create($company ?? $this->company, $number, $profile, null, $taxRegime, $defaultTaxes, new \DateTimeImmutable());
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
        return $this->companyPath().'/invoices'.(null === $id ? '' : '/'.$id);
    }
}
