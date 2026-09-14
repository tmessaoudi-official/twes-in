<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Domain;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceNotDraft;
use App\Module\Invoices\Domain\InvoiceStatus;
use App\Module\Invoices\Domain\InvoiceTax;
use App\Module\Invoices\Domain\InvoiceTransitionRefused;
use App\Module\Invoices\Domain\InvoiceType;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;

final class InvoiceTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private Company $company;
    private Company $globex;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), new \Symfony\Component\Clock\MockClock($this->now));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
    }

    public function testADraftInvoiceHoldsItsLinesDiscountsAndDocumentTaxesAsTheyStandToday(): void
    {
        $customer = $this->customer($this->company);
        $header = new InvoiceHeader(new \DateTimeImmutable('2026-09-10 17:00:00'), 45, ' PO-77 ', 'Merci', 'VIP', '12.5');

        $invoice = Invoice::create($this->company, $this->establishment(), $customer, $header, [
            new InvoiceLineDetails($this->product($this->company), 'Portable 14"', '2', $this->unit('C62'), '1250', '10', [$this->tax('FODEC'), $this->tax('TVA19')]),
            new InvoiceLineDetails(null, 'Pose', '1.5', $this->unit('HUR'), '40', null, []),
        ], [$this->tax('TIMBRE'), $this->tax('RS1')], $this->now);

        self::assertSame([InvoiceType::Invoice, InvoiceStatus::Draft, null, null], [$invoice->getType(), $invoice->getStatus(), $invoice->getNumber(), $invoice->getCorrectedInvoice()]);
        $kept = $invoice->getHeader();
        self::assertSame(['2026-09-10', 45, 'PO-77', 'Merci', 'VIP', '12.500'], [$kept->supplyDate?->format('Y-m-d'), $kept->paymentTermsDays, $kept->customerReference, $kept->notesPrinted, $kept->notesInternal, $kept->discountAmount]);
        $lines = $invoice->getLines();
        self::assertSame([1, 2], [$lines[0]->getPosition(), $lines[1]->getPosition()]);
        self::assertSame(['2.000', '1250.0000', '10.000'], [$lines[0]->getQuantity(), $lines[0]->getUnitPriceNet(), $lines[0]->getDiscountRate()]);
        self::assertNull($lines[1]->getDiscountRate(), 'no discount is no rate, not a zero rate');
        self::assertSame(['FODEC', 'TVA19'], array_map(static fn ($tax): string => $tax->getCode(), $lines[0]->getTaxes()));
        $documentTaxes = array_map(static fn (InvoiceTax $tax): array => [$tax->getPosition(), $tax->getCode(), $tax->getKind(), $tax->getRate(), $tax->getAmount(), $tax->getThreshold()], $invoice->getDocumentTaxes());
        self::assertSame([
            [1, 'TIMBRE', TaxKind::FixedDocument, null, '1.000', null],
            [2, 'RS1', TaxKind::WithholdingTotal, '1.000', null, '1000.000'],
        ], $documentTaxes);
    }

    public function testALineDiscountIsAPercentageFromZeroToAHundredWithThreeDecimals(): void
    {
        foreach (['-1', '100.001', '12.3456', 'ten'] as $rate) {
            $this->assertRefused('discountRate', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', $rate, []), $rate);
        }
        self::assertSame('100.000', new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', '100', [])->discountRate);
        self::assertSame('0.000', new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', '0', [])->discountRate);
        self::assertNull(new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', ' ', [])->discountRate);
    }

    public function testALineCountsInItsUnitAtAPriceAndCarriesOnlyLineTaxesEachOnce(): void
    {
        $this->assertRefused('quantity', fn () => new InvoiceLineDetails(null, 'Pièce', '1.5', $this->unit('C62'), '10', null, []));
        $this->assertRefused('quantity', fn () => new InvoiceLineDetails(null, 'Pièce', '0', $this->unit('C62'), '10', null, []));
        $this->assertRefused('unitPriceNet', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '-1', null, []));
        $this->assertRefused('description', fn () => new InvoiceLineDetails(null, ' ', '1', $this->unit('C62'), '10', null, []));
        $this->assertRefused('taxComponentIds', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', null, [$this->tax('TIMBRE')]));
        $this->assertRefused('taxComponentIds', fn () => new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', null, [$this->tax('TVA19'), $this->tax('TVA19')]));
    }

    public function testAHeaderKeepsItsTermsDiscountAndTextsWithinBounds(): void
    {
        foreach ([-1, 366] as $days) {
            $this->assertRefused('paymentTermsDays', static fn () => new InvoiceHeader(paymentTermsDays: $days), (string) $days);
        }
        foreach (['-1', '1.0001', 'x', '100000000000'] as $amount) {
            $this->assertRefused('discountAmount', static fn () => new InvoiceHeader(discountAmount: $amount), $amount);
        }
        $this->assertRefused('customerReference', static fn () => new InvoiceHeader(customerReference: str_repeat('a', 65)));
        $this->assertRefused('notesPrinted', static fn () => new InvoiceHeader(notesPrinted: str_repeat('a', 5001)));
        $this->assertRefused('notesInternal', static fn () => new InvoiceHeader(notesInternal: str_repeat('a', 5001)));
        $empty = new InvoiceHeader(null, 0, '', ' ', null, ' ');
        self::assertSame([0, null, null, null, null], [$empty->paymentTermsDays, $empty->customerReference, $empty->notesPrinted, $empty->notesInternal, $empty->discountAmount]);
        self::assertSame('0.000', new InvoiceHeader(discountAmount: '0')->discountAmount);
    }

    public function testADocumentCarriesFixedChargesAndWithholdingsEachOnceAndNoLineTax(): void
    {
        $customer = $this->customer($this->company);
        $this->assertRefused('documentTaxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(), [], [$this->tax('TVA19')], $this->now));
        $this->assertRefused('documentTaxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(), [], [$this->tax('TIMBRE'), $this->tax('TIMBRE')], $this->now));
    }

    public function testEverythingAnInvoiceNamesBelongsToItsCompany(): void
    {
        $mine = $this->customer($this->company);
        $theirEstablishment = $this->establishments->ofCompany($this->globex->getId())[0];

        $this->assertRefused('establishmentId', fn () => Invoice::create($this->company, $theirEstablishment, $mine, new InvoiceHeader(), [], [], $this->now));
        $this->assertRefused('customerId', fn () => Invoice::create($this->company, $this->establishment(), $this->customer($this->globex), new InvoiceHeader(), [], [], $this->now));
        $this->assertRefused('lines[0].productId', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [$this->pieceLine($this->product($this->globex))], [], $this->now));
        $this->assertRefused('lines[0].unitId', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [$this->pieceLine(unit: $this->unit('C62', $this->globex))], [], $this->now));
        $this->assertRefused('lines[0].taxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [$this->pieceLine(taxes: [$this->tax('TVA19', $this->globex)])], [], $this->now));
        $this->assertRefused('documentTaxComponentIds', fn () => Invoice::create($this->company, $this->establishment(), $mine, new InvoiceHeader(), [], [$this->tax('TIMBRE', $this->globex)], $this->now));
    }

    public function testARevisionNamesTheFieldsItChangedAndLeavesAnIdenticalInvoiceAlone(): void
    {
        $customer = $this->customer($this->company);
        $lines = [new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', null, [$this->tax('TVA19')])];
        $invoice = Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(customerReference: 'PO-1'), $lines, [$this->tax('TIMBRE')], $this->now);
        $later = $this->now->modify('+1 hour');

        self::assertSame([], $invoice->revise($this->establishment(), $customer, new InvoiceHeader(customerReference: 'PO-1'), $lines, [$this->tax('TIMBRE')], $later));
        self::assertEquals($this->now, $invoice->getUpdatedAt(), 'an identical revision changes nothing');

        $changed = $invoice->revise($this->establishment(), $this->customer($this->company, 'CLI-0002'), new InvoiceHeader(paymentTermsDays: 10, customerReference: 'PO-1', discountAmount: '1'), [
            new InvoiceLineDetails(null, 'Pièce', '1', $this->unit('C62'), '10', '5', [$this->tax('TVA19')]),
        ], [], $later);

        self::assertSame(['customerId', 'paymentTermsDays', 'discountAmount', 'documentTaxComponentIds', 'lines'], $changed);
        self::assertSame(['5.000', []], [$invoice->getLines()[0]->getDiscountRate(), $invoice->getDocumentTaxes()]);
        self::assertEquals($later, $invoice->getUpdatedAt());
    }

    public function testADraftIsCancelledOnceAndThenNeverRevised(): void
    {
        $customer = $this->customer($this->company);
        $invoice = Invoice::create($this->company, $this->establishment(), $customer, new InvoiceHeader(), [], [], $this->now);

        $invoice->cancel($this->now);

        self::assertSame(InvoiceStatus::Cancelled, $invoice->getStatus());
        $this->expectException(InvoiceTransitionRefused::class);
        try {
            $invoice->revise($this->establishment(), $customer, new InvoiceHeader(customerReference: 'late'), [], [], $this->now);
            self::fail('a cancelled invoice was revised');
        } catch (InvoiceNotDraft) {
        }
        $invoice->cancel($this->now);
    }

    /** @param list<TaxComponent> $taxes */
    private function pieceLine(?Product $product = null, ?Unit $unit = null, array $taxes = []): InvoiceLineDetails
    {
        return new InvoiceLineDetails($product, 'Pièce', '1', $unit ?? $this->unit('C62'), '10', null, $taxes);
    }

    /** @param \Closure(): mixed $attempt */
    private function assertRefused(string $field, \Closure $attempt, string $case = ''): void
    {
        try {
            $attempt();
            self::fail("$field accepted $case");
        } catch (InvalidInvoice $refused) {
            self::assertSame($field, $refused->field, $case);
        }
    }

    private function establishment(): Establishment
    {
        return $this->establishments->ofCompany($this->company->getId())[0];
    }

    private function customer(Company $company, string $number = 'CLI-0001'): Customer
    {
        $regime = new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->now);

        return Customer::create($company, $number, new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $this->now);
    }

    private function product(Company $company): Product
    {
        return Product::create($company, 'ART-001', new ProductDetails('Portable 14"', null, ProductKind::Goods, '1250'), $this->unit('C62', $company), null, [], $this->now);
    }

    private function unit(string $code, ?Company $company = null): Unit
    {
        $unit = $this->units->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($unit);

        return $unit;
    }

    private function tax(string $code, ?Company $company = null): TaxComponent
    {
        $tax = $this->taxes->ofCodeInCompany($code, ($company ?? $this->company)->getId());
        self::assertNotNull($tax);

        return $tax;
    }
}
