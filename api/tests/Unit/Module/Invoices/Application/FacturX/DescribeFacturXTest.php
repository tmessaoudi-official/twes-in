<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Invoices\Application\FacturX;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Invoices\Application\FacturX\CiiAllowance;
use App\Module\Invoices\Application\FacturX\CiiInvoice;
use App\Module\Invoices\Application\FacturX\CiiLine;
use App\Module\Invoices\Application\FacturX\CiiParty;
use App\Module\Invoices\Application\FacturX\CiiVatBreakdown;
use App\Module\Invoices\Application\FacturX\DescribeFacturX;
use App\Module\Invoices\Application\FacturX\FacturXRefused;
use App\Module\Invoices\Application\InvoiceNotFound;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceIssue;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryInvoices;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * An issued French invoice or credit note described in the EN 16931 terms a Factur-X file carries, from what issuing
 * fixed; every datum the standard needs and the document lacks is named instead.
 */
final class DescribeFacturXTest extends TestCase
{
    private MockClock $clock;
    private InMemoryUnits $units;
    private InMemoryTaxComponents $taxes;
    private InMemoryEstablishments $establishments;
    private InMemoryInvoices $invoices;
    private InvoiceTotals $totals;
    private DescribeFacturX $describe;
    private Company $company;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-09-15 09:00:00');
        $this->units = new InMemoryUnits();
        $this->taxes = new InMemoryTaxComponents();
        $this->establishments = new InMemoryEstablishments();
        $this->company = $this->provisioned(new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris'));
        $this->company->reviseProfile(self::sellerProfile());
        $this->invoices = new InMemoryInvoices();
        $this->totals = new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
        $this->describe = new DescribeFacturX($this->invoices, $this->totals, ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
    }

    public function testAStandardRateInvoiceIsDescribedAsIssuingFixedIt(): void
    {
        $invoice = $this->issued($this->customer('standard'), [$this->line('Réglage du tour', '2', 'C62', '150', null, 'TVA20')], new InvoiceHeader(new \DateTimeImmutable('2026-09-14'), 30, 'PO-77', 'Merci de votre confiance.'));

        $cii = $this->describe->describe($this->company, $invoice->getId());

        self::assertSame(['380', 'FA-2026-00001', '2026-09-15', 'EUR'], [$cii->typeCode, $cii->number, $cii->issueDate->format('Y-m-d'), $cii->currency]);
        self::assertSame(['Merci de votre confiance.'], $cii->notes);
        self::assertSame(['Atelier Durand SARL', '732829320', 'FR44732829320', '12 rue des Forges', null, '69007', 'Lyon', 'FR'], self::party($cii->seller));
        self::assertSame(['Garage Martin SAS', '542065479', 'FR82542065479', '3 avenue Foch', 'Bâtiment B', '75016', 'Paris', 'FR'], self::party($cii->buyer));
        self::assertSame(['PO-77', '2026-09-14', '2026-10-15'], [$cii->buyerReference, $cii->deliveryDate?->format('Y-m-d'), $cii->dueDate?->format('Y-m-d')]);
        self::assertSame(['FR7630006000011234567890189', 'AGRIFRPP'], [$cii->payeeIban, $cii->payeeBic]);
        self::assertSame([null, null], [$cii->precedingInvoiceNumber, $cii->precedingInvoiceIssueDate]);
        self::assertSame([['1', 'Réglage du tour', null, '150.0000', '2.000', 'C62', null, null, null, 'S', '20.00', null, '300.00']], array_map(self::lineRow(...), $cii->lines));
        self::assertSame([], $cii->allowances);
        self::assertSame([['S', '20.00', null, '300.00', '60.00']], array_map(self::breakdown(...), $cii->vatBreakdown));
        self::assertSame(['300.00', '0.00', '300.00', '60.00', '360.00', '360.00'], self::totals($cii));
    }

    public function testMixedRatesALineDiscountAndADocumentDiscountAddUpTheWayEn16931Checks(): void
    {
        $invoice = $this->issued($this->customer('standard'), [
            $this->line('Arbre usiné', '3', 'C62', '100', '10', 'TVA20'),
            $this->line('Main-d\'œuvre', '2', 'HUR', '45.5', null, 'TVA5_5'),
            $this->line('Graisse', '1', 'C62', '19.99', null, 'TVA10'),
        ], new InvoiceHeader(discountAmount: '20'));

        $cii = $this->describe->describe($this->company, $invoice->getId());

        self::assertSame([
            ['1', 'Arbre usiné', null, '100.0000', '3.000', 'C62', '10.00', '300.00', '30.00', 'S', '20.00', null, '270.00'],
            ['2', 'Main-d\'œuvre', null, '45.5000', '2.000', 'HUR', null, null, null, 'S', '5.50', null, '91.00'],
            ['3', 'Graisse', null, '19.9900', '1.000', 'C62', null, null, null, 'S', '10.00', null, '19.99'],
        ], array_map(self::lineRow(...), $cii->lines));
        // 20.00 off 380.99 of lines, pro rata by group: 14.1736, 4.7770 and 1.0494, floored to 19.98, the two cents left
        // going to the largest remainders (1.04 and 4.77), as DocumentCalculator allocates it.
        self::assertSame([['14.17', 'S', '20.00'], ['4.78', 'S', '5.50'], ['1.05', 'S', '10.00']], array_map(static fn (CiiAllowance $allowance): array => [$allowance->amount, $allowance->vat->category, $allowance->vat->rate], $cii->allowances));
        self::assertSame([
            ['S', '20.00', null, '255.83', '51.17'],
            ['S', '5.50', null, '86.22', '4.74'],
            ['S', '10.00', null, '18.94', '1.89'],
        ], array_map(self::breakdown(...), $cii->vatBreakdown));
        self::assertSame(['380.99', '20.00', '360.99', '57.80', '418.79', '418.79'], self::totals($cii));
        self::assertCoherent($cii);
    }

    public function testACreditNoteIsA381ReferencingTheInvoiceItCorrectsInPositiveAmounts(): void
    {
        $invoice = $this->issued($this->customer('standard'), [$this->line('Réglage du tour', '2', 'C62', '150', null, 'TVA20')]);
        $credit = Invoice::creditNoteFor($invoice, 'Pièce défectueuse', $this->clock->now());
        $credit->issue(new InvoiceIssue('AV-2026-00001', new \DateTimeImmutable('2026-09-20'), 0, 'fr', [], null, null, null), fn (Invoice $issuing) => $this->totals->issued($issuing), $this->clock->now());
        $this->invoices->save($credit);

        $cii = $this->describe->describe($this->company, $credit->getId());

        self::assertSame(['381', 'AV-2026-00001', '2026-09-20'], [$cii->typeCode, $cii->number, $cii->issueDate->format('Y-m-d')]);
        self::assertSame(['FA-2026-00001', '2026-09-15'], [$cii->precedingInvoiceNumber, $cii->precedingInvoiceIssueDate?->format('Y-m-d')]);
        self::assertSame(['Pièce défectueuse'], $cii->notes, 'why it corrects its invoice');
        self::assertSame([['1', 'Réglage du tour', null, '150.0000', '2.000', 'C62', null, null, null, 'S', '20.00', null, '300.00']], array_map(self::lineRow(...), $cii->lines));
        self::assertSame([['S', '20.00', null, '300.00', '60.00']], array_map(self::breakdown(...), $cii->vatBreakdown));
        self::assertSame(['300.00', '0.00', '300.00', '60.00', '360.00', '360.00'], self::totals($cii));
    }

    public function testASaleToAnotherEuBusinessCarriesNoVatUnderTheRegimesCategory(): void
    {
        $invoice = $this->issued($this->customer('intra_eu', [TaxFamily::Vat], identifiers: ['vat_number' => 'DE123456789'], country: 'DE'), [$this->line('Arbre usiné', '4', 'C62', '80', null)]);

        $cii = $this->describe->describe($this->company, $invoice->getId());

        self::assertSame([['1', 'Arbre usiné', null, '80.0000', '4.000', 'C62', null, null, null, 'K', '0.00', 'VATEX-EU-IC', '320.00']], array_map(self::lineRow(...), $cii->lines));
        self::assertSame([['K', '0.00', 'VATEX-EU-IC', '320.00', '0.00']], array_map(self::breakdown(...), $cii->vatBreakdown));
        self::assertSame(['DE123456789', 'DE'], [$cii->buyer->vatId, $cii->buyer->countryCode]);
        self::assertSame(['320.00', '0.00', '320.00', '0.00', '320.00', '320.00'], self::totals($cii));
    }

    public function testADocumentLackingWhatTheStandardAsksForIsRefusedNamingEveryGap(): void
    {
        $this->company->reviseProfile(new CompanyProfile(legalName: 'Atelier Durand SARL', identifiers: ['siret' => '73282932000013'], addressLine1: '12 rue des Forges'));
        $exempt = $this->customer('exempt', [TaxFamily::Vat], country: null);
        $invoice = $this->issued($exempt, [$this->line('Formation', '1', 'C62', '500', null)]);

        try {
            $this->describe->describe($this->company, $invoice->getId());
            self::fail('A document lacking what EN 16931 asks for was described.');
        } catch (FacturXRefused $refused) {
            self::assertSame(['incomplete_document', ['count' => 5]], [$refused->reason, $refused->params]);
            self::assertSame([
                ['code' => 'seller_siren_missing', 'params' => []],
                ['code' => 'seller_vat_number_missing', 'params' => []],
                ['code' => 'seller_address_incomplete', 'params' => ['missing' => ['postalCode', 'city']]],
                ['code' => 'buyer_address_incomplete', 'params' => ['missing' => ['countryCode']]],
                ['code' => 'vat_exemption_undeclared', 'params' => ['line' => 1, 'regime' => 'exempt']],
            ], $refused->gaps);
        }
    }

    public function testAnIntraEuSaleNeedsTheBuyersVatNumber(): void
    {
        $invoice = $this->issued($this->customer('intra_eu', [TaxFamily::Vat], identifiers: [], country: 'DE'), [$this->line('Arbre usiné', '1', 'C62', '80', null)]);

        try {
            $this->describe->describe($this->company, $invoice->getId());
            self::fail('An intra-EU sale was described without the buyer\'s VAT number.');
        } catch (FacturXRefused $refused) {
            self::assertSame([['code' => 'buyer_vat_number_missing', 'params' => []]], $refused->gaps);
        }
    }

    public function testADraftIsNotAnInvoiceYetAndAnotherCountrysDocumentIsNotWrittenAsFacturX(): void
    {
        $draft = Invoice::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], $this->customer('standard'), new InvoiceHeader(), [$this->line('Réglage', '1', 'C62', '10', null, 'TVA20')], [], $this->clock->now());
        $this->invoices->save($draft);
        try {
            $this->describe->describe($this->company, $draft->getId());
            self::fail('A draft was described.');
        } catch (FacturXRefused $refused) {
            self::assertSame(['not_issued', ['status' => 'draft'], []], [$refused->reason, $refused->params, $refused->gaps]);
        }

        $tunisian = $this->provisioned(new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'));
        $vat = $this->taxes->ofCodeInCompany('TVA19', $tunisian->getId());
        $unit = $this->units->ofCodeInCompany('C62', $tunisian->getId());
        self::assertNotNull($vat);
        self::assertNotNull($unit);
        $regime = new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->clock->now());
        $customer = Customer::create($tunisian, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'), null, $regime, [], $this->clock->now());
        $invoice = Invoice::create($tunisian, $this->establishments->ofCompany($tunisian->getId())[0], $customer, new InvoiceHeader(), [new InvoiceLineDetails(null, 'Pièce', '1', $unit, '10', null, [$vat])], [], $this->clock->now());
        $invoice->issue(new InvoiceIssue('FAC-2026-00001', new \DateTimeImmutable('2026-09-15'), 30, 'fr', [], null, null, null), fn (Invoice $issuing) => $this->totals->issued($issuing), $this->clock->now());
        $this->invoices->save($invoice);
        try {
            $this->describe->describe($tunisian, $invoice->getId());
            self::fail('A Tunisian invoice was described as Factur-X.');
        } catch (FacturXRefused $refused) {
            self::assertSame(['preset_not_supported', ['preset' => 'TN']], [$refused->reason, $refused->params]);
        }

        $this->expectException(InvoiceNotFound::class);
        $this->describe->describe($tunisian, $draft->getId());
    }

    public function testTheSameDocumentIsDescribedTheSameWayTwice(): void
    {
        $invoice = $this->issued($this->customer('standard'), [$this->line('Réglage du tour', '2', 'C62', '150', null, 'TVA20')]);

        self::assertEquals($this->describe->describe($this->company, $invoice->getId()), $this->describe->describe($this->company, $invoice->getId()));
    }

    /**
     * The EN 16931 sums (BR-CO-10, 11, 13, 14, 15, 16) and each category's base (BR-S-08) and tax (BR-CO-17), checked
     * here because the schematron that would check them is XSLT 2.0 and does not run in this suite.
     */
    public static function assertCoherent(CiiInvoice $cii): void
    {
        $minus = static fn (string $a, string $b): string => Decimal::format(Decimal::of($a)->sub(Decimal::of($b)), 2);
        $totals = $cii->totals;
        self::assertSame($totals->lineTotal, self::sumOf(array_map(static fn (CiiLine $line): string => $line->lineTotal, $cii->lines)), 'BR-CO-10');
        self::assertSame($totals->allowanceTotal, self::sumOf(array_map(static fn (CiiAllowance $allowance): string => $allowance->amount, $cii->allowances)), 'BR-CO-11');
        self::assertSame($totals->taxBasisTotal, $minus($totals->lineTotal, $totals->allowanceTotal), 'BR-CO-13');
        self::assertSame($totals->taxTotal, self::sumOf(array_map(static fn (CiiVatBreakdown $each): string => $each->tax, $cii->vatBreakdown)), 'BR-CO-14');
        self::assertSame($totals->grandTotal, self::sumOf([$totals->taxBasisTotal, $totals->taxTotal]), 'BR-CO-15');
        self::assertSame($totals->duePayable, $totals->grandTotal, 'BR-CO-16');
        foreach ($cii->vatBreakdown as $each) {
            $lines = array_values(array_filter($cii->lines, static fn (CiiLine $line): bool => $line->vat == $each->vat));
            $allowances = array_values(array_filter($cii->allowances, static fn (CiiAllowance $allowance): bool => $allowance->vat == $each->vat));
            self::assertSame($each->basis, $minus(self::sumOf(array_map(static fn (CiiLine $line): string => $line->lineTotal, $lines)), self::sumOf(array_map(static fn (CiiAllowance $allowance): string => $allowance->amount, $allowances))), 'BR-S-08');
            self::assertSame($each->tax, Decimal::format(Decimal::round(Decimal::of($each->basis)->mul(Decimal::of((string) $each->vat->rate))->div(100, Decimal::WORKING_SCALE), 2), 2), 'BR-CO-17');
        }
    }

    /** @param list<string> $amounts */
    private static function sumOf(array $amounts): string
    {
        $total = Decimal::zero();
        foreach ($amounts as $amount) {
            $total = $total->add(Decimal::of($amount));
        }

        return Decimal::format($total, 2);
    }

    /** @return list<string|null> */
    private static function party(CiiParty $party): array
    {
        return [$party->name, $party->legalId, $party->vatId, $party->line1, $party->line2, $party->postcode, $party->city, $party->countryCode];
    }

    /** @return list<string|null> */
    private static function lineRow(CiiLine $line): array
    {
        return [$line->id, $line->name, $line->sellerItemId, $line->netPrice, $line->quantity, $line->unitCode, $line->allowancePercent, $line->allowanceBasis, $line->allowanceAmount, $line->vat->category, $line->vat->rate, $line->vat->exemptionCode, $line->lineTotal];
    }

    /** @return list<string|null> */
    private static function breakdown(CiiVatBreakdown $each): array
    {
        return [$each->vat->category, $each->vat->rate, $each->vat->exemptionCode, $each->basis, $each->tax];
    }

    /** @return list<string> */
    private static function totals(CiiInvoice $cii): array
    {
        $t = $cii->totals;

        return [$t->lineTotal, $t->allowanceTotal, $t->taxBasisTotal, $t->taxTotal, $t->grandTotal, $t->duePayable];
    }

    private function provisioned(Company $company): Company
    {
        new ProvisionCompany(ShippedFiscalPresets::presets(), $this->taxes, $this->units, $this->establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $this->clock)->handle($company);

        return $company;
    }

    private static function sellerProfile(): CompanyProfile
    {
        return new CompanyProfile(
            legalName: 'Atelier Durand SARL',
            identifiers: ['siren' => '732829320', 'siret' => '73282932000013', 'vat_number' => 'FR44732829320'],
            addressLine1: '12 rue des Forges',
            postalCode: '69007',
            city: 'Lyon',
            iban: 'FR76 3000 6000 0112 3456 7890 189',
            bic: 'AGRIFRPP',
        );
    }

    /**
     * @param list<TaxFamily>       $excluded
     * @param array<string, string> $identifiers
     */
    private function customer(string $regime, array $excluded = [], ?array $identifiers = null, ?string $country = 'FR'): Customer
    {
        $now = $this->clock->now();
        $profile = new CustomerProfile(
            CustomerKind::Company,
            'Garage Martin',
            'Garage Martin SAS',
            $identifiers ?? ['siren' => '542065479', 'vat_number' => 'FR82542065479'],
            billingAddress: new PostalAddress('3 avenue Foch', 'Bâtiment B', '75016', 'Paris', $country),
        );

        return Customer::create($this->company, 'CLI-0001', $profile, null, new CustomerTaxRegime('FR', $regime, 'fiscal.regime.'.$regime, $excluded, null, 0, $now), [], $now);
    }

    private function line(string $description, string $quantity, string $unit, string $price, ?string $discountRate, string ...$taxes): InvoiceLineDetails
    {
        $unitEntity = $this->units->ofCodeInCompany($unit, $this->company->getId());
        self::assertNotNull($unitEntity);
        $components = [];
        foreach ($taxes as $code) {
            $components[] = $this->taxes->ofCodeInCompany($code, $this->company->getId()) ?? throw new \LogicException("No tax $code.");
        }

        return new InvoiceLineDetails(null, $description, $quantity, $unitEntity, $price, $discountRate, $components);
    }

    /** @param list<InvoiceLineDetails> $lines */
    private function issued(Customer $customer, array $lines, InvoiceHeader $header = new InvoiceHeader(paymentTermsDays: 30)): Invoice
    {
        $invoice = Invoice::create($this->company, $this->establishments->ofCompany($this->company->getId())[0], $customer, $header, $lines, [], $this->clock->now());
        $invoice->issue(new InvoiceIssue('FA-2026-00001', new \DateTimeImmutable('2026-09-15'), $header->paymentTermsDays ?? 30, 'fr', [], null, null, null), fn (Invoice $issuing) => $this->totals->issued($issuing), $this->clock->now());
        $invoice->releaseEvents();
        $this->invoices->save($invoice);

        return $invoice;
    }
}
