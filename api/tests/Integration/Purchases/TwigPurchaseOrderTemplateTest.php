<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Purchases;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Purchases\Application\PurchaseOrderPage;
use App\Module\Purchases\Application\PurchaseOrderTemplate;
use App\Module\Purchases\Application\PurchaseOrderTotals;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderHeader;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderLineDetails;
use App\Module\Purchases\Domain\ValueObject\VendorSnapshot;
use App\Shared\Domain\ValueObject\DocumentWatermark;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The Twig layout of an order to a vendor against real orders, in each language; no database, no renderer. */
final class TwigPurchaseOrderTemplateTest extends KernelTestCase
{
    private PurchaseOrder $order;
    private Company $company;
    private Establishment $establishment;
    private Unit $piece;

    protected function setUp(): void
    {
        $clock = new MockClock('2026-10-18 09:00:00');
        $now = $clock->now();
        $units = new InMemoryUnits();
        $establishments = new InMemoryEstablishments();
        $company = new Company('Acme Distribution', 'TN', 'TND', 'fr', 'Africa/Tunis');
        new ProvisionCompany(ShippedFiscalPresets::presets(), new InMemoryTaxComponents(), $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), $clock)->handle($company);
        $piece = $units->ofCodeInCompany('C62', $company->getId());
        $kilogram = $units->ofCodeInCompany('KGM', $company->getId());
        self::assertNotNull($piece);
        self::assertNotNull($kilogram);
        [$this->company, $this->establishment, $this->piece] = [$company, $establishments->ofCompany($company->getId())[0], $piece];
        $screws = Uuid::v7();
        $this->order = PurchaseOrder::create($company, $this->establishment, Uuid::v7(), 'Quincaillerie du Sud', new PurchaseOrderHeader(new \DateTimeImmutable('2026-11-02'), "Livrer le matin.\nQuai 3.", 'Négocié par téléphone'), [
            new PurchaseOrderLineDetails($screws, 'VIS-6', 'Vis <6 mm>', '500', $piece, '0.25'),
            new PurchaseOrderLineDetails(Uuid::v7(), 'FIL-2', 'Fil de fer', '3', $kilogram, '0.3333'),
        ], $now);
        $this->order->keepVendorCodes([$screws->toRfc4122() => 'QDS/61-A']);
        $this->order->send('BCF-2026-10-00001', new \DateTimeImmutable('2026-10-18'), new VendorSnapshot('FRN-0001', 'Quincaillerie du Sud', 'Quincaillerie du Sud SARL', ['matricule_fiscal' => '7654321BAM000'], 'Route de Gabès', null, '3000', 'Sfax', 'TN', 'ventes@qds.tn', '+216 74 000 000'), $now);
    }

    public function testASentOrderNamesItsVendorItsBuyerItsLinesAndWhatItComesToInFrench(): void
    {
        $html = $this->html(null, 'fr');

        foreach ([
            '<html lang="fr" dir="ltr">', 'Bon de commande', 'BCF-2026-10-00001', '18/10/2026', '02/11/2026',
            'Fournisseur', 'Quincaillerie du Sud SARL', 'FRN-0001', 'Matricule fiscal', '7654321BAM000', 'Route de Gabès', 'Sfax',
            'Acme Distribution', 'VIS-6', 'Vis &lt;6 mm&gt;', 'Réf. fournisseur', '<td><bdi>QDS/61-A</bdi></td>', 'Fil de fer', '<td>Unité</td>',
            // A price prints at its own precision, never below the currency's: the vendor reads what the line comes to.
            '0,250', '0,3333', '125,000', '1,000', '126,000', 'Total HT', 'Montants en TND',
            'Livrer le matin.<br />', 'Quai 3.',
        ] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        foreach (['BROUILLON', 'ANNULÉ', 'Négocié par téléphone', '0,2500', '<script', 'pdf.', 'purchase_order.'] as $absent) {
            self::assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function testADraftAndACancelledOrderSayItAcrossThePageInEnglish(): void
    {
        foreach ([[PurchaseOrderPage::DRAFT, 'DRAFT'], [PurchaseOrderPage::CANCELLED, 'CANCELLED']] as [$watermark, $said]) {
            $html = $this->html($watermark, 'en');
            foreach (['<html lang="en" dir="ltr">', 'Purchase order', 'Vendor', 'Vendor ref.', 'Expected on', $said, '126.000'] as $expected) {
                self::assertStringContainsString($expected, $html, $watermark);
            }
        }
    }

    /** The Arabic title, « طلبية », is feminine, and the stamp across it agrees. */
    public function testACancelledOrderIsStampedInAgreementWithItsArabicTitle(): void
    {
        self::assertStringContainsString('<div class="watermark" style="font-size: '.new DocumentWatermark()->wordSizePtFor('ملغاة').'pt">ملغاة</div>', $this->html(PurchaseOrderPage::CANCELLED, 'ar'));
        self::assertStringContainsString('<div class="watermark" style="font-size: '.new DocumentWatermark()->wordSizePtFor('ANNULÉ').'pt">ANNULÉ</div>', $this->html(PurchaseOrderPage::CANCELLED, 'fr'));
    }

    public function testInArabicThePageReadsRightToLeftAndEachTypedValueReadsItsOwnWay(): void
    {
        $html = $this->html(null, 'ar');

        foreach (['<html lang="ar" dir="rtl">', 'طلبية لدى مزوّد', 'مرجع المزوّد', '<bdi>QDS/61-A</bdi>', '<bdi>BCF-2026-10-00001</bdi>', '<bdi>FRN-0001</bdi>', '<bdi>VIS-6</bdi>', '<p dir="auto"'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    public function testAnOrderNoLineOfWhichHasAVendorCodeDrawsNoColumnForIt(): void
    {
        $this->order = PurchaseOrder::create($this->company, $this->establishment, Uuid::v7(), 'Quincaillerie du Sud', new PurchaseOrderHeader(null, null, null), [
            new PurchaseOrderLineDetails(Uuid::v7(), 'VIS-6', 'Vis 6 mm', '5', $this->piece, null),
        ], new \DateTimeImmutable('2026-10-18'));
        $this->order->send('BCF-2026-10-00002', new \DateTimeImmutable('2026-10-18'), new VendorSnapshot('FRN-0001', 'Quincaillerie du Sud', null, [], null, null, null, null, null, null, null), new \DateTimeImmutable('2026-10-18'));

        $html = $this->html(null, 'en');

        self::assertStringContainsString('Vis 6 mm', $html);
        self::assertStringNotContainsString('Vendor ref.', $html);
    }

    public function testTheCompanysFormatsWinOverTheLanguageAndTheUnitsAreSummed(): void
    {
        $html = $this->html(null, 'fr', 'ymd', 'comma-dot', [new QuantityTotal('Pièce', 0, '500'), new QuantityTotal('Kilogramme', 3, '3')]);

        foreach (['2026-10-18', '2026-11-02', '126.000', '0.3333', 'data-testid="quantities"'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
        self::assertStringNotContainsString('18/10/2026', $html);
    }

    /**
     * @param PurchaseOrderPage::DRAFT|PurchaseOrderPage::CANCELLED|null $watermark
     * @param list<QuantityTotal>                                        $quantities
     */
    private function html(?string $watermark, string $language, string $dateFormat = 'auto', string $numberFormat = 'auto', array $quantities = []): string
    {
        self::bootKernel();
        $template = static::getContainer()->get(PurchaseOrderTemplate::class);
        self::assertInstanceOf(PurchaseOrderTemplate::class, $template);
        $vendor = $this->order->getVendorSnapshot();
        $buyer = $this->order->getBuyerSnapshot();
        self::assertInstanceOf(VendorSnapshot::class, $vendor);
        self::assertInstanceOf(SellerSnapshot::class, $buyer);

        return $template->html(new PurchaseOrderPage($this->order, PurchaseOrderTotals::of($this->order, 3), $vendor, $buyer, $watermark, $language, $dateFormat, $numberFormat, quantities: $quantities));
    }
}
