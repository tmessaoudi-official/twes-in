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
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Factures d'acompte (docs/fiscal TN.md and FR.md § 2b): drawn from an accepted quote for a share of each rate group,
 * issued and numbered as invoices, then given back on the final invoice as they were charged.
 */
final class DepositInvoicesTest extends ApiTestCase
{
    private const array WRITER = ['quote.read', 'quote.write', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'customer.read'];

    private Company $company;
    private Customer $customer;
    private string $productId;
    /** The rate the quote's labour line carries, which the company's country offers. */
    private string $labourTax = 'TVA19';

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

    /**
     * The quote: 2250.000 carrying FODEC and VAT, 60.000 carrying VAT, 2775.675 tax included. Its 30 % deposit nets
     * 675.000 and 18.000, and the final invoice gives back what the deposit charged (pricing vector
     * `final-invoice-tax-is-the-whole-less-what-deposits-charged`): together they charge the quote's 443.175 of VAT.
     */
    public function testADepositTakesAShareOfEachRateGroupAndTheFinalInvoiceGivesItBackAsCharged(): void
    {
        $this->signedIn(self::WRITER);
        $quoteId = $this->accepted();

        $this->postJson($this->quotePath($quoteId).'/deposit-invoices', ['depositPercentage' => '30', 'depositAmount' => null]);

        self::assertResponseIsSuccessful();
        $deposits = $this->rows($this->json(), 'deposits');
        self::assertCount(1, $deposits);
        $depositId = $this->stringAt($deposits[0], 'invoiceId');
        self::assertSame(['draft', null, '833.703'], [$deposits[0]['status'], $deposits[0]['number'], $deposits[0]['total']]);

        $deposit = $this->invoice($depositId);
        self::assertSame([true, $quoteId], [$deposit['deposit'], $deposit['quoteId']]);
        $lines = $this->rows($deposit, 'lines');
        self::assertSame(['675.0000', '18.0000'], array_column($lines, 'unitPriceNet'));
        self::assertSame([[$this->taxId('FODEC'), $this->taxId('TVA19')], [$this->taxId('TVA19')]], array_column($lines, 'taxComponentIds'));
        self::assertStringContainsString('30', $this->stringAt($lines[0], 'description'));
        self::assertSame(['693.000', '139.703', '833.703'], [$deposit['subtotalNet'], $deposit['totalTax'], $deposit['total']], 'and the stamp of its own');

        $this->postJson($this->quotePath($quoteId).'/invoice', null);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a deposit still a draft is issued or cancelled before the final invoice');

        $this->issue($depositId);
        $depositNumber = $this->stringAt($this->invoice($depositId), 'number');

        $this->postJson($this->quotePath($quoteId).'/invoice', null);
        self::assertResponseIsSuccessful();
        $finalId = $this->stringAt($this->json(), 'invoiceId');
        $final = $this->invoice($finalId);
        self::assertSame([false, $quoteId], [$final['deposit'], $final['quoteId']]);
        $lines = $this->rows($final, 'lines');
        self::assertSame([null, null, $depositId, $depositId], array_column($lines, 'deductsInvoiceId'));
        self::assertSame(['675.0000', '18.0000'], [$lines[2]['unitPriceNet'], $lines[3]['unitPriceNet']]);
        self::assertStringContainsString($depositNumber, $this->stringAt($lines[2], 'description'), 'a deduction names the deposit it gives back');
        self::assertSame(['1617.000', '325.972', '1943.972'], [$final['subtotalNet'], $final['totalTax'], $final['total']]);

        $this->issue($finalId);
        $final = $this->invoice($finalId);
        self::assertSame(['1943.972', '-675.000'], [$final['amountDue'], $this->rows($final, 'lines')[2]['net']], 'issued as drafted');
        self::assertSame('325.972', $final['totalTax']);
    }

    public function testADepositIsGivenBackOnceAndOnlyAsTheAPIBuildsIt(): void
    {
        $this->signedIn(self::WRITER);
        $quoteId = $this->accepted();
        $depositId = $this->deposit($quoteId, ['depositPercentage' => '30', 'depositAmount' => null]);
        $this->issue($depositId);
        $this->postJson($this->quotePath($quoteId).'/invoice', null);
        $finalId = $this->stringAt($this->json(), 'invoiceId');

        // The screen echoes the deduction with another price: the API prices it from the deposit again.
        $body = $this->invoice($finalId);
        $lines = $this->rows($body, 'lines');
        $lines[2]['unitPriceNet'] = '1.0000';
        $lines[2]['taxComponentIds'] = [];
        $this->sendJson('PUT', $this->companyPath().'/invoices/'.$finalId, [...$this->editable($body), 'lines' => $lines]);
        self::assertResponseIsSuccessful();
        self::assertSame(['675.0000', '18.0000'], array_column(\array_slice($this->rows($this->json(), 'lines'), 2), 'unitPriceNet'));

        // Another invoice of the customer cannot give the same deposit back while the final draft stands.
        $this->postJson($this->companyPath().'/invoices', $this->handInvoice([['deductsInvoiceId' => $depositId, 'quantity' => '1']]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('deductsInvoiceId', $this->stringAt($this->json(), 'detail'));

        // Once that draft is cancelled, it can, and a deduction of something that is not a deposit is refused.
        $this->postJson($this->companyPath().'/invoices/'.$finalId.'/cancel', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->companyPath().'/invoices', $this->handInvoice([['description' => 'Solde', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '2000', 'taxComponentIds' => [$this->taxId('TVA19')]], ['deductsInvoiceId' => $depositId, 'quantity' => '1']]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $handId = $this->stringAt($this->json(), 'id');
        self::assertCount(3, $this->rows($this->json(), 'lines'), 'the deposit given back line by line');
        $this->postJson($this->companyPath().'/invoices', $this->handInvoice([['deductsInvoiceId' => $handId, 'quantity' => '1']]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        // A duplicate is a new sale and gives nothing back; a credit note of an invoice reverses its deductions too.
        $this->issue($handId);
        $this->postJson($this->companyPath().'/invoices/'.$handId.'/duplicate', null);
        self::assertSame(['Solde'], array_column($this->rows($this->json(), 'lines'), 'description'), 'the deposit lines are not charged again either');
        $this->postJson($this->companyPath().'/invoices/'.$handId.'/credit-notes', ['creditNoteReason' => 'Annulation']);
        self::assertResponseIsSuccessful();
        $credit = $this->json();
        self::assertSame([null, $depositId, $depositId], array_column($this->rows($credit, 'lines'), 'deductsInvoiceId'));
        self::assertSame($this->negated($this->stringAt($this->invoice($handId), 'total')), $credit['total']);
    }

    public function testADepositCreditedAfterTheFinalDraftIsNotGivenBackAtIssue(): void
    {
        $this->signedIn(self::WRITER);
        $quoteId = $this->accepted();
        $depositId = $this->deposit($quoteId, ['depositPercentage' => '10', 'depositAmount' => null]);
        $this->issue($depositId);
        $this->postJson($this->quotePath($quoteId).'/invoice', null);
        $finalId = $this->stringAt($this->json(), 'invoiceId');

        $this->postJson($this->companyPath().'/invoices/'.$depositId.'/credit-notes', ['creditNoteReason' => 'Acompte annulé']);
        $this->issue($this->stringAt($this->json(), 'id'));

        $this->postJson($this->companyPath().'/invoices/'.$finalId.'/issue', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the draft gave back what the credit note since gave back');
        self::assertSame('draft', $this->invoice($finalId)['status']);

        $this->postJson($this->companyPath().'/invoices', $this->handInvoice([['description' => 'Solde', 'quantity' => '1', 'unitId' => $this->unitId('C62'), 'unitPriceNet' => '2000', 'taxComponentIds' => [$this->taxId('TVA19')]], ['deductsInvoiceId' => $depositId, 'quantity' => '1']]));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'nor on a new document');
    }

    public function testADepositIsAShareOrAnAmountTheQuoteLeaves(): void
    {
        $this->signedIn(self::WRITER);
        $draft = $this->draft();
        $this->postJson($this->quotePath($draft).'/deposit-invoices', ['depositPercentage' => '30', 'depositAmount' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'only an accepted quote takes a deposit');

        $quoteId = $this->accepted();
        foreach ([
            ['depositPercentage' => '0', 'depositAmount' => null],
            ['depositPercentage' => '100', 'depositAmount' => null],
            ['depositPercentage' => 'trente', 'depositAmount' => null],
            ['depositPercentage' => null, 'depositAmount' => null],
            ['depositPercentage' => '10', 'depositAmount' => '100'],
            ['depositPercentage' => null, 'depositAmount' => '2775.675'],
            ['depositPercentage' => null, 'depositAmount' => '0.0001'],
        ] as $body) {
            $this->postJson($this->quotePath($quoteId).'/deposit-invoices', $body);
            self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, json_encode($body, \JSON_THROW_ON_ERROR));
        }
        self::assertEquals(0, $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM invoice'), 'a refusal drafts nothing');

        // 555.135 is a fifth of the quote tax included: 450.000 and 12.000 net.
        $first = $this->deposit($quoteId, ['depositPercentage' => null, 'depositAmount' => '555.135']);
        self::assertSame(['450.0000', '12.0000'], array_column($this->rows($this->invoice($first), 'lines'), 'unitPriceNet'));
        self::assertStringNotContainsString('%', $this->stringAt($this->rows($this->invoice($first), 'lines')[0], 'description'));

        $this->postJson($this->quotePath($quoteId).'/deposit-invoices', ['depositPercentage' => '81', 'depositAmount' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'the deposits of a quote never go beyond it');
        $this->deposit($quoteId, ['depositPercentage' => '80', 'depositAmount' => null]);

        $this->postJson($this->companyPath().'/invoices/'.$first.'/cancel', null);
        $this->getJson($this->quotePath($quoteId));
        self::assertSame(['cancelled', 'draft'], array_column($this->rows($this->json(), 'deposits'), 'status'));
    }

    /**
     * A deposit is an invoice whose VAT fell due when it was paid, so an accountant's file must tell it from a final
     * invoice: the list, its kind filter and the export all name it a deposit, and `invoice` asks for the others.
     */
    public function testTheListItsKindFilterAndTheExportTellADepositFromAnInvoice(): void
    {
        $this->signedIn(self::WRITER);
        $depositId = $this->deposit($this->accepted(), ['depositPercentage' => '30', 'depositAmount' => null]);
        $this->postJson($this->companyPath().'/invoices', $this->handInvoice([['productId' => $this->productId, 'quantity' => '1']]));
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $plainId = $this->stringAt($this->json(), 'id');

        foreach ([
            'documentType[]=deposit' => [$depositId],
            'documentType[]=invoice' => [$plainId],
            'documentType[]=invoice&documentType[]=deposit' => [$depositId, $plainId],
            'documentType[]=credit_note' => [],
        ] as $query => $ids) {
            $this->getJson($this->companyPath().'/invoices?'.$query);
            self::assertResponseIsSuccessful($query);
            $listed = array_map(fn (array $row): string => $this->stringAt($row, 'id'), $this->jsonList());
            sort($listed);
            sort($ids);
            self::assertSame($ids, $listed, $query);
        }

        $this->stepUp('password-1234');
        $this->client->request('GET', $this->companyPath().'/exports/invoices.csv');
        self::assertResponseIsSuccessful();
        $lines = array_values(array_filter(explode("\n", $this->client->getInternalResponse()->getContent()), static fn (string $line): bool => '' !== trim($line)));
        $header = array_map(strval(...), str_getcsv(ltrim($lines[0], "\xEF\xBB\xBF"), escape: ''));
        $types = array_map(static fn (string $line): string => (string) array_combine($header, array_map(strval(...), str_getcsv($line, escape: '')))['type'], \array_slice($lines, 1));
        sort($types);
        self::assertSame(['deposit', 'invoice'], $types);
    }

    /**
     * A currency of two decimals: the deposit's net is worked out again in cents while its line holds three decimals,
     * and giving it back on the final invoice must read them as the same amount.
     */
    public function testAFrenchDepositInEurosIsGivenBackOnTheFinalInvoice(): void
    {
        $this->inFrance();
        $this->signedIn(self::WRITER);
        $quoteId = $this->accepted();
        $depositId = $this->deposit($quoteId, ['depositPercentage' => '30', 'depositAmount' => null]);
        $drawn = $this->invoice($depositId);
        self::assertNull($drawn['operationCategory'] ?? null, 'the quote\'s hand-written line says nothing of what its operations are');
        $this->postJson($this->companyPath().'/invoices/'.$depositId.'/issue', []);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'France asks what they are');
        $this->sendJson('PUT', $this->companyPath().'/invoices/'.$depositId, [...$this->editable($drawn), 'operationCategory' => 'both', 'lines' => array_map(static fn (array $line): array => array_intersect_key($line, array_flip(['description', 'quantity', 'unitId', 'unitPriceNet', 'taxComponentIds'])), $this->rows($drawn, 'lines'))]);
        self::assertResponseIsSuccessful();
        $this->issue($depositId);
        $deposit = $this->invoice($depositId);
        self::assertSame('both', $deposit['operationCategory']);

        $this->postJson($this->quotePath($quoteId).'/invoice', null);

        self::assertResponseIsSuccessful();
        $final = $this->invoice($this->stringAt($this->json(), 'invoiceId'));
        $givingBack = array_values(array_filter($this->rows($final, 'lines'), static fn (array $line): bool => $depositId === ($line['deductsInvoiceId'] ?? null)));
        self::assertCount(2, $givingBack, 'one line a rate group, as the deposit charged them');
        self::assertSame(['217.5000', '18.0000'], array_column($givingBack, 'unitPriceNet'), 'what the deposit charged net, in cents');
        $this->getJson($this->quotePath($quoteId));
        $whole = $this->stringAt($this->json(), 'subtotalNet');
        self::assertSame(0, Decimal::of($this->stringAt($final, 'subtotalNet'))->add(Decimal::of($this->stringAt($deposit, 'subtotalNet')))->compare(Decimal::of($whole)), 'the final invoice and its deposit charge the quote, no more');
    }

    public function testADepositAsksForWritingInvoices(): void
    {
        $this->signedIn(['quote.read', 'quote.write']);
        $quoteId = $this->accepted();

        $this->postJson($this->quotePath($quoteId).'/deposit-invoices', ['depositPercentage' => '30', 'depositAmount' => null]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    private function accepted(): string
    {
        $id = $this->draft();
        $this->postJson($this->quotePath($id).'/send', null);
        self::assertResponseIsSuccessful();
        $this->postJson($this->quotePath($id).'/accept', ['answeredOn' => null]);
        self::assertResponseIsSuccessful();

        return $id;
    }

    private function draft(): string
    {
        $this->postJson($this->companyPath().'/quotes', [
            'customerId' => $this->customer->getId()->toRfc4122(),
            'establishmentId' => null,
            'customerReference' => null,
            'notesPrinted' => null,
            'notesInternal' => null,
            'discountAmount' => null,
            'lines' => [
                ['productId' => $this->productId, 'quantity' => '2', 'discountRate' => '10'],
                ['description' => 'Pose', 'quantity' => '1.5', 'unitId' => $this->unitId('HUR'), 'unitPriceNet' => '40', 'taxComponentIds' => [$this->taxId($this->labourTax)]],
            ],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @param array<string, mixed> $body */
    private function deposit(string $quoteId, array $body): string
    {
        $this->postJson($this->quotePath($quoteId).'/deposit-invoices', $body);
        self::assertResponseIsSuccessful();
        $deposits = $this->rows($this->json(), 'deposits');

        return $this->stringAt($deposits[\count($deposits) - 1], 'invoiceId');
    }

    private function issue(string $invoiceId): void
    {
        $this->postJson($this->companyPath().'/invoices/'.$invoiceId.'/issue', []);
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function invoice(string $id): array
    {
        $this->getJson($this->companyPath().'/invoices/'.$id);
        self::assertResponseIsSuccessful();

        return $this->json();
    }

    /**
     * @param list<array<string, mixed>> $lines
     *
     * @return array<string, mixed>
     */
    private function handInvoice(array $lines): array
    {
        return [
            'customerId' => $this->customer->getId()->toRfc4122(),
            'establishmentId' => null,
            'lines' => $lines,
        ];
    }

    /**
     * What a revision sends back of a read document.
     *
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function editable(array $body): array
    {
        return array_intersect_key($body, array_flip(['customerId', 'establishmentId', 'supplyDate', 'paymentTermsDays', 'customerReference', 'notesPrinted', 'notesInternal', 'discountAmount', 'documentTaxComponentIds']));
    }

    /**
     * A list of objects of a JSON body, each asserted to be one.
     *
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

    private function negated(string $amount): string
    {
        return str_starts_with($amount, '-') ? substr($amount, 1) : '-'.$amount;
    }

    /** The same setting in a French company, able to issue: its identifiers, address and late payment rate given. */
    private function inFrance(): void
    {
        $this->company = new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->em()->persist($this->company);
        $this->em()->flush();
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        static::getContainer()->get(SyncCustomerTaxRegimes::class)->handle();
        $this->company->reviseProfile(new CompanyProfile(
            legalName: 'Atelier Durand SARL',
            identifiers: ['siren' => '732829320', 'siret' => '73282932000074', 'vat_number' => 'FR44732829320'],
            addressLine1: '12 rue des Forges',
            postalCode: '69007',
            city: 'Lyon',
        ));
        $this->em()->flush();
        static::getContainer()->get(ChangeSettings::class)->change(new SettingContext($this->company), 'document.late_payment_rate', SettingLevel::Company, 'trois fois le taux d’intérêt légal', null);
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('FR', 'standard');
        self::assertNotNull($regime);
        $profile = new CustomerProfile(CustomerKind::Company, 'Garage Martin', 'Garage Martin SAS', ['siren' => '542065479', 'vat_number' => 'FR82542065479'], billingAddress: new PostalAddress('3 avenue Foch', null, '75016', 'Paris', 'FR'));
        $this->customer = Customer::create($this->company, 'CLI-0001', $profile, null, $regime, [], new \DateTimeImmutable());
        $this->em()->persist($this->customer);
        // 402.78 a unit, two less 10 %: 725.004, of which 30 % is 217.50 in cents and 217.501 in three decimals.
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Tour CNC', null, ProductKind::Goods, '402.78'), $this->unit('C62'), null, [$this->tax('TVA20')->getId()], new \DateTimeImmutable());
        $this->em()->persist($product);
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
        $this->labourTax = 'TVA10';
    }

    private function customer(string $number): Customer
    {
        $regime = static::getContainer()->get(CustomerTaxRegimeRepository::class)->ofPresetAndCode('TN', 'standard');
        self::assertNotNull($regime);
        $customer = Customer::create($this->company, $number, self::aTunisianBusiness('Carthage Conseil'), null, $regime, [], new \DateTimeImmutable());
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

    private function quotePath(string $id): string
    {
        return $this->companyPath().'/quotes/'.$id;
    }
}
