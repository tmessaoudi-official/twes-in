<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\DeliveryNotes;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\DeliveryNotes\Application\DeliveryNotePage;
use App\Module\DeliveryNotes\Application\DeliveryNoteTemplate;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
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
        $this->note->validate('BL-2026-00001', new \DateTimeImmutable('2026-09-15'), $now);
        $this->totals = new DeliveryNoteTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
    }

    public function testAValidatedNotePrintsWhatItNamesWithItsFiguresAndItsCustomersMentionInFrench(): void
    {
        $html = $this->html(null, true, 'fr', 'Marchandise voyageant aux risques du client.');

        foreach ([
            '<html lang="fr">', 'Bon de livraison', 'BL-2026-00001', '15/09/2026', '20/09/2026', 'PO-77',
            'Acme Distribution', 'Carthage Conseil SARL', 'CLI-0007', 'Matricule fiscal', '1234567APM000', 'Rue de Rome',
            'Portable &lt;14&quot;&gt;', 'C62', "1\u{a0}250,000", "2\u{a0}500,000", 'FODEC', "2\u{a0}525,000",
            'Exportation exonérée de la TVA.', 'Livrer au quai 3.', 'Marchandise voyageant aux risques du client.',
            // The reception block (docs/SPEC.md § 7, 2026-09-24 22:51, on by default): three cells and a line for réserves.
            'Réception', 'Date et heure', 'Nom', 'Signature et cachet', 'Réserves',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringNotContainsString('BROUILLON', $html);
        self::assertStringNotContainsString('<script', $html, 'a printed page runs nothing');
    }

    public function testWithoutPricesAndInEnglishACancelledNoteSaysSo(): void
    {
        $html = $this->html(DeliveryNotePage::CANCELLED, false, 'en', '');

        foreach (['<html lang="en">', 'Delivery note', 'CANCELLED', 'BL-2026-00001', 'Carthage Conseil SARL', 'Quantity', 'Date and time', 'Signature and stamp', 'Reservations'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        foreach (['1,250.000', '2,525.000', 'FODEC', 'fiscal.mention', 'pdf.'] as $absent) {
            self::assertStringNotContainsString($absent, $html);
        }
    }

    public function testTheCompanysDateAndNumberFormatWinOverTheLanguage(): void
    {
        $html = $this->html(null, true, 'fr', '', dateFormat: 'ymd', numberFormat: 'comma-dot');

        foreach (['<html lang="fr">', 'Bon de livraison', '2026-09-15', '2026-09-20', '1,250.000', '2,525.000'] as $expected) {
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

    /** @param DeliveryNotePage::DRAFT|DeliveryNotePage::CANCELLED|null $watermark */
    private function html(?string $watermark, bool $showPrices, string $language, string $printedNotes, bool $receptionBlock = true, string $dateFormat = 'auto', string $numberFormat = 'auto'): string
    {
        self::bootKernel();
        $template = static::getContainer()->get(DeliveryNoteTemplate::class);
        self::assertInstanceOf(DeliveryNoteTemplate::class, $template);
        $snapshot = $this->note->getCustomerSnapshot();
        self::assertInstanceOf(CustomerSnapshot::class, $snapshot);

        return $template->html(new DeliveryNotePage($this->note, $this->totals->of($this->note), $snapshot, $watermark, $showPrices, $language, $printedNotes, $receptionBlock, $dateFormat, $numberFormat));
    }
}
