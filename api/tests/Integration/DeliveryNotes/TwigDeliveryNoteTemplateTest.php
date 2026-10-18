<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\DeliveryNotes;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Customers\Domain\ValueObject\CustomerSnapshot;
use App\Module\DeliveryNotes\Application\DeliveryNotePage;
use App\Module\DeliveryNotes\Application\DeliveryNoteTemplate;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNotePrint;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\ValueObject\PrintSettings;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/** The Twig layout against real notes, in both languages; no database, no renderer. */
final class TwigDeliveryNoteTemplateTest extends KernelTestCase
{
    private DeliveryNote $note;
    private DeliveryNoteTotals $totals;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-09-15 09:00:00');
        $now = $clock->now();
        $units = new InMemoryUnits();
        $taxes = new InMemoryTaxComponents();
        $establishments = new InMemoryEstablishments();
        $company = new Company('Acme Distribution', 'TN', 'TND', 'fr', 'Africa/Tunis');
        new ProvisionCompany(ShippedFiscalPresets::presets(), $taxes, $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock)->handle($company);
        $customer = Customer::create($company, 'CLI-0007', new CustomerProfile(
            CustomerKind::Company,
            'Carthage Conseil',
            'Carthage Conseil SARL',
            ['matricule_fiscal' => '1234567APM000'],
            billingAddress: new PostalAddress('Rue de Rome', null, '1000', 'Tunis', 'TN'),
        ), null, new CustomerTaxRegime('TN', 'export', 'fiscal.regime.export', [], 'fiscal.mention.tn.export', 0, $now), [], $now);
        $piece = $units->ofCodeInCompany('C62', $company->getId());
        $levy = $taxes->ofCodeInCompany('FODEC', $company->getId());
        self::assertNotNull($piece);
        self::assertNotNull($levy);
        $this->note = DeliveryNote::create($company, $establishments->ofCompany($company->getId())[0], $customer, new DeliveryNoteHeader(new \DateTimeImmutable('2026-09-20'), customerReference: 'PO-77', remarksPrinted: 'Livrer au quai 3.'), [
            new DeliveryNoteLineDetails(null, 'Portable <14">', '2', $piece, '1250', [$levy]),
        ], $now);
        $this->note->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15'), new DeliveryNotePrint('fr', true, true, new PrintSettings('', 'auto', 'auto')), $now);
        $this->totals = new DeliveryNoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
    }

    public function testAValidatedNotePrintsWhatItNamesWithItsFiguresAndItsCustomersMentionInFrench(): void
    {
        $html = $this->html(null, true, 'fr', 'Marchandise voyageant aux risques du client.');

        foreach ([
            '<html lang="fr" dir="ltr">', 'Bon de livraison', 'BL-2026-00001', '15/09/2026', '20/09/2026', 'PO-77',
            'Acme Distribution', 'Carthage Conseil SARL', 'CLI-0007', 'Matricule fiscal', '1234567APM000', 'Rue de Rome',
            'Portable &lt;14&quot;&gt;', '<td>Unité</td>', "1\u{a0}250,000", "2\u{a0}500,000", 'FODEC', "2\u{a0}525,000",
            'Exportation exonérée de la TVA.', 'Livrer au quai 3.', 'Marchandise voyageant aux risques du client.',
            // The reception block (docs/SPEC.md § 7, 2026-09-24 22:51, on by default): three cells and a line for réserves.
            'Réception', 'Date et heure', 'Nom', 'Signature et cachet', 'Réserves',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringNotContainsString('BROUILLON', $html);
        self::assertStringNotContainsString('<script', $html, 'a printed page runs nothing');
        // The unit as a reader names it; its UN/ECE code is the e-invoice's word, not the page's.
        self::assertStringNotContainsString('C62', $html);
    }

    public function testWithoutPricesAndInEnglishACancelledNoteSaysSo(): void
    {
        $html = $this->html(DeliveryNotePage::CANCELLED, false, 'en', '');

        foreach (['<html lang="en" dir="ltr">', 'Delivery note', 'CANCELLED', 'BL-2026-00001', 'Carthage Conseil SARL', 'Quantity', 'Date and time', 'Signature and stamp', 'Reservations'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        foreach (['1,250.000', '2,525.000', 'FODEC', 'fiscal.mention', 'pdf.'] as $absent) {
            self::assertStringNotContainsString($absent, $html);
        }
    }

    /**
     * A delivered note prints when, to the minute, on its company's clock: 14:32 UTC is 15:32 in Tunis. The seconds are
     * kept and never printed, as nobody signing a paper observed them (docs/SPEC.md § 7, 2026-09-21 17:45).
     */
    public function testADeliveredNotePrintsItsDayAndTimeToTheMinuteOnTheCompanysClock(): void
    {
        $this->note->deliver(new \DateTimeImmutable('2026-09-15 14:32:07', new \DateTimeZone('UTC')), new \DateTimeImmutable('2026-09-16 09:00:00'));

        $html = $this->html(null, true, 'fr', '');

        self::assertStringContainsString('15/09/2026 15:32', $html);
        self::assertStringNotContainsString('15:32:07', $html);
        self::assertStringNotContainsString('20/09/2026', $html, 'the day it was expected gives way to the moment it came');
    }

    public function testTheCompanysDateAndNumberFormatWinOverTheLanguage(): void
    {
        $html = $this->html(null, true, 'fr', '', dateFormat: 'ymd', numberFormat: 'comma-dot');

        foreach (['<html lang="fr" dir="ltr">', 'Bon de livraison', '2026-09-15', '2026-09-20', '1,250.000', '2,525.000'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        foreach (['15/09/2026', "1\u{a0}250,000"] as $absent) {
            self::assertStringNotContainsString($absent, $html);
        }
    }

    public function testACompanyMayLeaveTheReceptionBlockOut(): void
    {
        $html = $this->html(null, true, 'fr', '', false);

        self::assertStringContainsString('BL-2026-00001', $html);
        foreach (['Réception', 'Signature et cachet', 'Réserves', 'Reçu par'] as $absent) {
            self::assertStringNotContainsString($absent, $html);
        }
    }

    public function testWhatTheNoteCarriesInEachUnitIsPrintedUnderItsLines(): void
    {
        self::assertStringNotContainsString('data-testid="quantities"', $this->html(null, false, 'fr', ''));

        $html = $this->html(null, false, 'en', '', quantities: [new QuantityTotal('Piece', 0, '12'), new QuantityTotal('kg', 3, '3.5')]);
        self::assertMatchesRegularExpression('#data-testid="quantities">Quantities: 12 Piece · 3.500 kg</p>#u', $html);
    }

    /**
     * The customer's exemption is stated with the article the company gives, a percent sign in it included, and not at
     * all while it gives none.
     */
    public function testTheCustomersExemptionMentionPrintsWithItsReference(): void
    {
        $now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $exempt = CustomerSnapshot::of(Customer::create(new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris'), 'CLI-0008', new CustomerProfile(CustomerKind::Company, 'Clinique Pasteur'), null, new CustomerTaxRegime('FR', 'exempt', 'fiscal.regime.exempt', [], 'fiscal.mention.fr.exempt', 0, $now), [], $now));

        self::assertStringContainsString('<p class="mention">Exonération de TVA, article 261-4-1° du CGI.</p>', $this->html(null, false, 'fr', '', customer: $exempt, exemptionReference: 'article 261-4-1° du CGI'));
        self::assertStringContainsString('<p class="mention">Exonération de TVA, article 5 (taux 0 %).</p>', $this->html(null, false, 'fr', '', customer: $exempt, exemptionReference: 'article 5 (taux 0 %)'));
        $without = $this->html(null, false, 'fr', '', customer: $exempt);
        self::assertStringNotContainsString('Exonération de TVA', $without);
        self::assertStringNotContainsString('%reference%', $without);
    }

    /**
     * @param DeliveryNotePage::DRAFT|DeliveryNotePage::CANCELLED|null $watermark
     * @param list<QuantityTotal>                                      $quantities
     */
    private function html(?string $watermark, bool $showPrices, string $language, string $printedNotes, bool $receptionBlock = true, string $dateFormat = 'auto', string $numberFormat = 'auto', array $quantities = [], ?CustomerSnapshot $customer = null, ?string $exemptionReference = null): string
    {
        self::bootKernel();
        $template = static::getContainer()->get(DeliveryNoteTemplate::class);
        self::assertInstanceOf(DeliveryNoteTemplate::class, $template);
        $snapshot = $this->note->getCustomerSnapshot();
        self::assertInstanceOf(CustomerSnapshot::class, $snapshot);
        $seller = $this->note->getSellerSnapshot();
        self::assertInstanceOf(SellerSnapshot::class, $seller);

        return $template->html(new DeliveryNotePage($this->note, $this->totals->of($this->note), $customer ?? $snapshot, $seller, $watermark, $showPrices, $language, $printedNotes, $receptionBlock, $dateFormat, $numberFormat, quantities: $quantities, exemptionReference: $exemptionReference));
    }
}
