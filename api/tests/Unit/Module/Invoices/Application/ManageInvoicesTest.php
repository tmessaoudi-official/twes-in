<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\InvoiceInput;
use App\Module\Invoices\Application\InvoiceLineInput;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\ManageInvoices;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTax;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryCustomers;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageInvoicesTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private InMemoryCustomers $customers;
    private InMemoryProducts $products;
    private InMemoryAuditTrail $audit;
    private ProvisionCompany $provision;
    private ManageInvoices $manage;
    private InvoiceTotals $totals;
    private Company $company;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $this->provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $this->clock);
        $this->customers = new InMemoryCustomers();
        $this->products = new InMemoryProducts();
        $this->audit = new InMemoryAuditTrail();
        $this->totals = new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
        $this->manage = new ManageInvoices(new InMemoryInvoices(), $this->customers, $this->products, $this->units, $this->taxes, $this->establishments, $this->totals, $this->audit, $this->clock);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($this->company);
    }

    public function testALineNamingAProductStartsFromItAndDropsTheTaxesTheCustomersRegimeDoesNotCharge(): void
    {
        $customer = $this->customer('export', [TaxFamily::Vat]);
        $product = $this->product(['FODEC', 'TVA19']);
        $actor = Uuid::v7();

        $invoice = $this->manage->create($this->company, $this->input($customer, [new InvoiceLineInput($product->getId(), null, '2')]), $actor);

        self::assertSame([$invoice], $this->manage->list($this->company));
        self::assertSame($invoice, $this->manage->get($this->company, $invoice->getId()));
        self::assertTrue($invoice->getEstablishment()->isDefault(), 'an invoice without an establishment is the default one\'s');
        $line = $invoice->getLines()[0];
        self::assertSame(['Portable 14"', 'C62', '1250.0000', null], [$line->getDescription(), $line->getUnit()->getCode(), $line->getUnitPriceNet(), $line->getDiscountRate()]);
        self::assertSame(['FODEC'], array_map(static fn (InvoiceLineTax $tax): string => $tax->getCode(), $line->getTaxes()));
        $entry = $this->audit->entries[0];
        self::assertSame(['invoice', 'invoice.created', $actor, [], $this->company->getId()], [$entry->entityType, $entry->action, $entry->actorUserId, $entry->changes, $entry->companyId]);
    }

    public function testDocumentTaxesLeftOutAreTheCompanysDefaultsAndTheCustomersOwnLessWhatItsRegimeRefuses(): void
    {
        $payer = $this->customer('standard', [], [$this->tax('RS1')->getId()]);
        $invoice = $this->manage->create($this->company, $this->input($payer, []), null);
        self::assertSame(['TIMBRE', 'RS1'], $this->documentTaxCodes($invoice->getDocumentTaxes()));

        $unstamped = $this->customer('unstamped', [TaxFamily::Stamp], [$this->tax('RS1')->getId(), $this->tax('TVA19')->getId()], 'CLI-0002');
        $invoice = $this->manage->create($this->company, $this->input($unstamped, []), null);
        self::assertSame(['RS1'], $this->documentTaxCodes($invoice->getDocumentTaxes()), 'no stamp for a regime refusing it, and a line tax default stays on lines');

        $this->retire($this->tax('TIMBRE'));
        $invoice = $this->manage->create($this->company, $this->input($this->customer(), []), null);
        self::assertSame([], $this->documentTaxCodes($invoice->getDocumentTaxes()), 'a retired default is not offered');
    }

    public function testDocumentTaxesAnInvoiceStatesAreItsCompanysActiveOnesTheRegimeCharges(): void
    {
        $customer = $this->customer();
        $invoice = $this->manage->create($this->company, $this->input($customer, [], [$this->tax('RS1')->getId()]), null);
        self::assertSame(['RS1'], $this->documentTaxCodes($invoice->getDocumentTaxes()), 'stating them leaves the defaults out');
        self::assertSame([], $this->documentTaxCodes($this->manage->create($this->company, $this->input($customer, [], []), null)->getDocumentTaxes()), 'an empty list is none');

        $this->assertRefused('documentTaxComponentIds', fn () => $this->manage->create($this->company, $this->input($customer, [], [Uuid::v7()]), null));
        $this->assertRefused('documentTaxComponentIds', fn () => $this->manage->create($this->company, $this->input($this->customer('unstamped', [TaxFamily::Stamp], number: 'CLI-0002'), [], [$this->tax('TIMBRE')->getId()]), null));

        $kept = $this->manage->create($this->company, $this->input($customer, [], [$this->tax('TIMBRE')->getId()]), null);
        $this->retire($this->tax('TIMBRE'));
        $this->assertRefused('documentTaxComponentIds', fn () => $this->manage->create($this->company, $this->input($customer, [], [$this->tax('TIMBRE')->getId()]), null));
        $this->manage->revise($this->company, $kept->getId(), $this->input($customer, [], [$this->tax('TIMBRE')->getId()], new InvoiceHeader(customerReference: 'kept')), null);
        self::assertSame(['TIMBRE'], $this->documentTaxCodes($kept->getDocumentTaxes()), 'a retired tax stays on the draft that already names it');
    }

    public function testTheTotalsApplyTheLineDiscountTheDocumentDiscountTheStampAndTheWithholding(): void
    {
        $customer = $this->customer();
        $line = new InvoiceLineInput(null, 'Conseil', '1', $this->unit('C62')->getId(), '1000', '10', [$this->tax('TVA19')->getId()]);
        $taxes = [$this->tax('TIMBRE')->getId(), $this->tax('RS1')->getId()];

        $totals = $this->totals->of($this->manage->create($this->company, $this->input($customer, [$line], $taxes), null));

        // 1000 less 10 % is 900, VAT 171: 1071 reaches the 1000 threshold, so 1 % of it is withheld; the stamp is outside.
        self::assertSame(['900.000', '171.000', '1072.000', '1061.290'], [$totals->subtotalNet, $totals->totalTax, $totals->total, $totals->amountDue]);
        self::assertSame([['RS1', '10.710']], array_map(static fn ($tax): array => [$tax->code, $tax->amount], $totals->withholdings));

        $totals = $this->totals->of($this->manage->create($this->company, $this->input($customer, [$line], $taxes, new InvoiceHeader(discountAmount: '100')), null));

        // 800 after the document discount, VAT 152: 952 is under the threshold, nothing is withheld.
        self::assertSame(['900.000', '100.000', '800.000', '152.000', '953.000', '953.000', []], [$totals->subtotalNet, $totals->documentDiscount, $totals->netAfterDocumentDiscount, $totals->totalTax, $totals->total, $totals->amountDue, $totals->withholdings]);
    }

    public function testADocumentDiscountFitsTheCurrencyAndNeverExceedsTheLines(): void
    {
        $line = new InvoiceLineInput(null, 'Conseil', '1', $this->unit('C62')->getId(), '900', null, []);
        $this->assertRefused('discountAmount', fn () => $this->manage->create($this->company, $this->input($this->customer(), [$line], [], new InvoiceHeader(discountAmount: '900.001')), null));
        self::assertSame('900.000', $this->totals->of($this->manage->create($this->company, $this->input($this->customer(), [$line], [], new InvoiceHeader(discountAmount: '900')), null))->documentDiscount);

        $euros = new Company('Dupont', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $this->provision->handle($euros);
        $regime = new CustomerTaxRegime('FR', 'standard', 'fiscal.regime.standard', [], null, 0, $this->clock->now());
        $client = Customer::create($euros, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Dupont SA'), null, $regime, [], $this->clock->now());
        $this->customers->save($client);
        $unit = $this->units->ofCodeInCompany('C62', $euros->getId());
        self::assertNotNull($unit);
        $this->assertRefused('discountAmount', fn () => $this->manage->create($euros, new InvoiceInput($client->getId(), null, new InvoiceHeader(discountAmount: '1.005'), [new InvoiceLineInput(null, 'Conseil', '1', $unit->getId(), '100', null, [])], []), null));
    }

    public function testARevisionIsAuditedWithTheNamesOfTheFieldsItChangedAndADraftIsCancelledOnce(): void
    {
        $customer = $this->customer();
        $invoice = $this->manage->create($this->company, $this->input($customer, [], []), null);

        $this->manage->revise($this->company, $invoice->getId(), $this->input($customer, [], [], new InvoiceHeader(paymentTermsDays: 60)), null);
        $this->manage->revise($this->company, $invoice->getId(), $this->input($customer, [], [], new InvoiceHeader(paymentTermsDays: 60)), null);
        $cancelled = $this->manage->cancel($this->company, $invoice->getId(), null);

        self::assertSame(InvoiceStatus::Cancelled, $cancelled->getStatus());
        self::assertSame(['invoice.created', 'invoice.revised', 'invoice.cancelled'], array_map(static fn ($entry): string => $entry->action, $this->audit->entries), 'an identical revision is not audited');
        self::assertSame(['fields' => ['paymentTermsDays']], $this->audit->entries[1]->changes);
        $this->expectException(InvoiceTransitionRefused::class);
        $this->manage->cancel($this->company, $invoice->getId(), null);
    }

    public function testAnotherCompanysInvoiceIsNotFound(): void
    {
        $globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->provision->handle($globex);
        $invoice = $this->manage->create($this->company, $this->input($this->customer(), [], []), null);

        $this->expectException(InvoiceNotFound::class);
        $this->manage->get($globex, $invoice->getId());
    }

    /**
     * @param list<InvoiceLineInput> $lines
     * @param list<Uuid>|null        $documentTaxes
     */
    private function input(Customer $customer, array $lines, ?array $documentTaxes = null, InvoiceHeader $header = new InvoiceHeader()): InvoiceInput
    {
        return new InvoiceInput($customer->getId(), null, $header, $lines, $documentTaxes);
    }

    /**
     * @param list<TaxFamily> $excluded
     * @param list<Uuid>      $defaultTaxes
     */
    private function customer(string $regime = 'standard', array $excluded = [], array $defaultTaxes = [], string $number = 'CLI-0001'): Customer
    {
        $taxRegime = new CustomerTaxRegime('TN', $regime, 'fiscal.regime.'.$regime, $excluded, null, 0, $this->clock->now());
        $customer = Customer::create($this->company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $taxRegime, $defaultTaxes, $this->clock->now());
        $this->customers->save($customer);

        return $customer;
    }

    /** @param list<string> $taxes */
    private function product(array $taxes): Product
    {
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $this->unit('C62'), null, array_map(fn (string $code): Uuid => $this->tax($code)->getId(), $taxes), $this->clock->now());
        $this->products->save($product);

        return $product;
    }

    private function unit(string $code): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function tax(string $code): TaxComponent
    {
        $tax = $this->taxes->ofCodeInCompany($code, $this->company->getId());
        self::assertNotNull($tax);

        return $tax;
    }

    /** Retires a tax, everything else as it is. */
    private function retire(TaxComponent $tax): void
    {
        $tax->revise($tax->getName(), $tax->getRate(), $tax->getAmount(), $tax->getThreshold(), $tax->entersVatBase(), $tax->isDefault(), false, $tax->getExemptionMention(), $tax->getSortOrder(), 3, $this->clock->now());
    }

    /**
     * @param list<InvoiceTax> $taxes
     *
     * @return list<string>
     */
    private function documentTaxCodes(array $taxes): array
    {
        return array_map(static fn (InvoiceTax $tax): string => $tax->getCode(), $taxes);
    }

    /** @param \Closure(): mixed $attempt */
    private function assertRefused(string $field, \Closure $attempt): void
    {
        try {
            $attempt();
            self::fail("$field accepted");
        } catch (InvalidInvoice $refused) {
            self::assertSame($field, $refused->field);
        }
    }
}
