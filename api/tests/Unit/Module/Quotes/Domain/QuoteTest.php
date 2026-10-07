<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Quotes\Domain;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\Unit;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteHeader;
use App\Module\Quotes\Domain\QuoteLineDetails;
use App\Module\Quotes\Domain\QuoteLineTax;
use App\Module\Quotes\Domain\QuoteNotDraft;
use App\Module\Quotes\Domain\QuotePrint;
use App\Module\Quotes\Domain\QuoteStatus;
use App\Module\Quotes\Domain\QuoteTransitionRefused;
use App\Shared\Domain\PrintSettings;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\Establishment;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class QuoteTest extends TestCase
{
    private \DateTimeImmutable $now;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private Company $company;
    private Company $globex;
    private Customer $customer;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-10-01 09:00:00');
        $this->now = $clock->now();
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $provision = new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->globex = new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $provision->handle($this->company);
        $provision->handle($this->globex);
        $this->customer = $this->customer($this->company);
    }

    public function testADraftHoldsItsLinesInOrderWithTheirDiscountAndTheRatesItsTaxesHaveToday(): void
    {
        $product = $this->product($this->company);

        $quote = Quote::create($this->company, $this->establishment(), $this->customer, new QuoteHeader('RFQ-12', 'Livraison comprise.', 'Client pressé', '50'), [
            new QuoteLineDetails($product, 'Tour CNC, réglage', '3', $this->unit('C62'), '1250.5', '10', [$this->tax('FODEC'), $this->tax('TVA19')]),
            new QuoteLineDetails(null, 'Installation', '1.5', $this->unit('HUR'), '80', null, []),
        ], $this->now);

        self::assertSame(QuoteStatus::Draft, $quote->getStatus());
        self::assertNull($quote->getNumber());
        self::assertNull($quote->getValidUntil());
        self::assertSame(['RFQ-12', 'Livraison comprise.', 'Client pressé', '50.000'], [$quote->getHeader()->customerReference, $quote->getHeader()->notesPrinted, $quote->getHeader()->notesInternal, $quote->getHeader()->discountAmount]);
        [$first, $second] = $quote->getLines();
        self::assertSame([1, $product, '3.000', '1250.5000', '10.000'], [$first->getPosition(), $first->getProduct(), $first->getQuantity(), $first->getUnitPriceNet(), $first->getDiscountRate()]);
        self::assertSame(
            [['FODEC', '1.000', true], ['TVA19', '19.000', false]],
            array_map(static fn (QuoteLineTax $tax): array => [$tax->getCode(), $tax->getRate(), $tax->entersVatBase()], $first->getTaxes()),
        );
        self::assertSame([2, null, '1.500', null], [$second->getPosition(), $second->getProduct(), $second->getQuantity(), $second->getDiscountRate()]);
    }

    public function testALineIsWrittenTheWayAnInvoiceLineIs(): void
    {
        $this->assertRefused('quantity', fn () => new QuoteLineDetails(null, 'X', '0', $this->unit('C62'), '1', null, []));
        $this->assertRefused('quantity', fn () => new QuoteLineDetails(null, 'X', '1.5', $this->unit('C62'), '1', null, []), 'a piece counts whole');
        $this->assertRefused('unitPriceNet', fn () => new QuoteLineDetails(null, 'X', '1', $this->unit('C62'), '-1', null, []));
        $this->assertRefused('discountRate', fn () => new QuoteLineDetails(null, 'X', '1', $this->unit('C62'), '1', '100.5', []));
        $this->assertRefused('description', fn () => new QuoteLineDetails(null, '  ', '1', $this->unit('C62'), '1', null, []));
        $this->assertRefused('taxComponentIds', fn () => new QuoteLineDetails(null, 'X', '1', $this->unit('C62'), '1', null, [$this->tax('TVA19'), $this->tax('TVA19')]));
        $this->assertRefused('taxComponentIds', fn () => new QuoteLineDetails(null, 'X', '1', $this->unit('C62'), '1', null, [$this->tax('TIMBRE')]), 'a document tax');
        $this->assertRefused('discountAmount', static fn () => new QuoteHeader(discountAmount: '1.2345'));
    }

    public function testEverythingAQuoteNamesIsItsOwnCompanys(): void
    {
        $this->assertRefused('customerId', fn () => Quote::create($this->company, $this->establishment(), $this->customer($this->globex), new QuoteHeader(), [], $this->now));
        $this->assertRefused('lines[0].unitId', fn () => Quote::create($this->company, $this->establishment(), $this->customer, new QuoteHeader(), [
            new QuoteLineDetails(null, 'X', '1', $this->unit('C62', $this->globex), '1', null, []),
        ], $this->now));
        $this->assertRefused('lines[0].productId', fn () => Quote::create($this->company, $this->establishment(), $this->customer, new QuoteHeader(), [
            new QuoteLineDetails($this->product($this->globex), 'X', '1', $this->unit('C62'), '1', null, []),
        ], $this->now));
    }

    public function testARevisionNamesWhatChangedAndOnlyADraftIsRevised(): void
    {
        $quote = $this->draft();

        self::assertSame([], $quote->revise($this->establishment(), $this->customer, new QuoteHeader(), [$this->line('10')], $this->now));
        self::assertSame(['customerReference', 'discountAmount', 'lines'], $quote->revise($this->establishment(), $this->customer, new QuoteHeader('RFQ-1', discountAmount: '1'), [$this->line('12')], $this->now));

        $this->send($quote);
        $this->expectException(QuoteNotDraft::class);
        $quote->revise($this->establishment(), $this->customer, new QuoteHeader(), [$this->line('13')], $this->now);
    }

    public function testSendingNumbersItFixesItsDaysAndKeepsWhatItSaysThatDay(): void
    {
        $quote = $this->draft();

        $quote->send('DEV-2026-10-00001', new \DateTimeImmutable('2026-10-01 23:30:00'), 30, new QuotePrint('fr', true, new PrintSettings('Merci', 'auto', 'auto')), $this->now);

        self::assertSame(QuoteStatus::Sent, $quote->getStatus());
        self::assertSame('DEV-2026-10-00001', $quote->getNumber());
        self::assertSame(['2026-10-01', '2026-10-31'], [$quote->getIssueDate()?->format('Y-m-d'), $quote->getValidUntil()?->format('Y-m-d')]);
        self::assertSame('Carthage Conseil', $quote->getCustomerSnapshot()?->name);
        self::assertSame('Acme', $quote->getSellerSnapshot()?->name);
        self::assertSame(['fr', true, 'Merci'], [$quote->getPrintSettings()?->language, $quote->getPrintSettings()?->signatureBlock, $quote->getPrintSettings()?->print->printedNotes]);

        $this->expectException(QuoteNotDraft::class);
        $this->send($quote);
    }

    public function testAQuoteIsSentWithALineAndAPriceThatBindsForADayAtLeast(): void
    {
        $empty = Quote::create($this->company, $this->establishment(), $this->customer, new QuoteHeader(), [], $this->now);
        $this->assertRefused('lines', fn () => $this->send($empty));
        $this->assertRefused('validityDays', fn () => $this->draft()->send('DEV-1', $this->now, 0, $this->print(), $this->now));
    }

    public function testItExpiresTheDayAfterItsValidityDateAndOnlyWhileItIsSent(): void
    {
        $draft = $this->draft();
        self::assertFalse($draft->isExpired(new \DateTimeImmutable('2027-01-01')), 'a draft has no date to pass');

        $quote = $this->draft();
        $quote->send('DEV-1', new \DateTimeImmutable('2026-10-01'), 30, $this->print(), $this->now);
        self::assertFalse($quote->isExpired(new \DateTimeImmutable('2026-10-31 23:59:00')), 'the price holds on its last day');
        self::assertTrue($quote->isExpired(new \DateTimeImmutable('2026-11-01 00:00:00')));

        $quote->accept(new \DateTimeImmutable('2026-11-02'), new \DateTimeImmutable('2026-11-02'), $this->now);
        self::assertFalse($quote->isExpired(new \DateTimeImmutable('2026-11-03')), 'an answered quote no longer waits');
    }

    public function testASentQuoteIsAcceptedEvenExpiredOnADayFromItsIssueToToday(): void
    {
        $quote = $this->sent();
        $this->assertRefused('answeredOn', fn () => $quote->accept(new \DateTimeImmutable('2026-09-30'), new \DateTimeImmutable('2026-12-01'), $this->now), 'before its issue day');
        $this->assertRefused('answeredOn', fn () => $quote->accept(new \DateTimeImmutable('2026-12-02'), new \DateTimeImmutable('2026-12-01'), $this->now), 'after today');

        $quote->accept(new \DateTimeImmutable('2026-12-01'), new \DateTimeImmutable('2026-12-01'), $this->now);

        self::assertSame([QuoteStatus::Accepted, '2026-12-01'], [$quote->getStatus(), $quote->getAnsweredOn()?->format('Y-m-d')]);
        $this->expectException(QuoteTransitionRefused::class);
        $quote->refuse($this->now, null, $this->now, $this->now);
    }

    public function testARefusalKeepsWhyWhenTheCompanyWasTold(): void
    {
        $quote = $this->sent();
        $this->assertRefused('reason', fn () => $quote->refuse($this->now, str_repeat('x', Quote::REASON_MAX + 1), $this->now, $this->now));
        self::assertSame(QuoteStatus::Sent, $quote->getStatus(), 'a refused refusal changes nothing');

        $quote->refuse($this->now, '  Trop cher.  ', $this->now, $this->now);
        self::assertSame([QuoteStatus::Refused, 'Trop cher.'], [$quote->getStatus(), $quote->getRefusalReason()]);

        $silent = $this->sent();
        $silent->refuse($this->now, '   ', $this->now, $this->now);
        self::assertNull($silent->getRefusalReason());
    }

    public function testOnlyADraftIsCancelledAndOnlyASentQuoteIsAnswered(): void
    {
        $draft = $this->draft();
        try {
            $draft->accept($this->now, $this->now, $this->now);
            self::fail('a draft was accepted');
        } catch (QuoteTransitionRefused) {
        }
        $draft->cancel($this->now);
        self::assertSame(QuoteStatus::Cancelled, $draft->getStatus());

        $this->expectException(QuoteNotDraft::class);
        $this->sent()->cancel($this->now);
    }

    public function testOnlyAnAcceptedQuoteIsInvoiced(): void
    {
        $sent = $this->sent();
        try {
            $sent->markInvoiced(Uuid::v7(), $this->now);
            self::fail('a sent quote was invoiced');
        } catch (QuoteTransitionRefused) {
        }
        self::assertNull($sent->getInvoiceId());

        $sent->accept($this->now, $this->now, $this->now);
        $invoice = Uuid::v7();
        $sent->markInvoiced($invoice, $this->now);
        self::assertSame($invoice, $sent->getInvoiceId());
    }

    private function draft(): Quote
    {
        return Quote::create($this->company, $this->establishment(), $this->customer, new QuoteHeader(), [$this->line('10')], $this->now);
    }

    private function sent(): Quote
    {
        $quote = $this->draft();
        $this->send($quote);

        return $quote;
    }

    private function send(Quote $quote): void
    {
        $quote->send('DEV-2026-10-00001', new \DateTimeImmutable('2026-10-01'), 30, $this->print(), $this->now);
    }

    private function print(): QuotePrint
    {
        return new QuotePrint('fr', true, new PrintSettings('', 'auto', 'auto'));
    }

    private function line(string $price): QuoteLineDetails
    {
        return new QuoteLineDetails(null, 'Pièce', '1', $this->unit('C62'), $price, null, []);
    }

    /** @param \Closure(): mixed $attempt */
    private function assertRefused(string $field, \Closure $attempt, string $case = ''): void
    {
        try {
            $attempt();
            self::fail("$field accepted $case");
        } catch (InvalidQuote $refused) {
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
        return Product::create($company, 'ART-001', new ProductDetails('Tour CNC', null, ProductKind::Goods, '1250'), $this->unit('C62', $company), null, [], $this->now);
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
