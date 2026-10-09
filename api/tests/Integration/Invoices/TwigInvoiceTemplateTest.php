<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Invoices;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\CustomerSnapshot;
use App\Module\Invoices\Application\InvoiceMentions;
use App\Module\Invoices\Application\InvoicePage;
use App\Module\Invoices\Application\InvoiceTemplate;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Shared\Domain\DocumentDesign;
use App\Shared\Domain\DocumentLayout;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tenancy\Domain\SellerSnapshot;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\ShippedFiscalPresets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The invoice layout against a real draft, in every design (docs/SPEC.md § 7, 2026-10-01 23:30): whatever the layout and
 * the accent, every legal mention any preset prints is printed, filled, and the watermark is there. No database, no renderer.
 */
final class TwigInvoiceTemplateTest extends KernelTestCase
{
    private const string TEMPLATES = __DIR__.'/../../../templates/pdf';

    private Invoice $invoice;
    private InvoiceTotals $totals;

    protected function setUp(): void
    {
        $now = new \DateTimeImmutable('2026-10-07 09:00:00');
        $units = new InMemoryUnits();
        $taxes = new InMemoryTaxComponents();
        $establishments = new InMemoryEstablishments();
        $company = new Company('Atelier Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        new ProvisionCompany(ShippedFiscalPresets::presets(), $taxes, $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), new \Symfony\Component\Clock\MockClock($now))->handle($company);
        $customer = Customer::create($company, 'CLI-0007', new CustomerProfile(CustomerKind::Company, 'Garage Martin'), null, new CustomerTaxRegime('FR', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $unit = $units->ofCodeInCompany('C62', $company->getId());
        $vat = $taxes->ofCodeInCompany('TVA20', $company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($vat);
        $this->invoice = Invoice::create($company, $establishments->ofCompany($company->getId())[0], $customer, new InvoiceHeader(), [new InvoiceLineDetails(null, 'Réglage du tour', '2', $unit, '150', null, [$vat])], [], $now);
        $this->totals = new InvoiceTotals(ShippedFiscalPresets::presets(), ShippedFiscalPresets::scales());
    }

    public function testEveryLayoutPrintsEveryMentionFilledAndTheWatermark(): void
    {
        self::bootKernel();
        $translator = static::getContainer()->get(TranslatorInterface::class);
        [$keys, $parameters] = $this->everyMention();
        self::assertGreaterThanOrEqual(10, \count($keys), 'the mentions of both presets are found, so an empty set cannot pass');

        foreach (DocumentLayout::cases() as $layout) {
            foreach (['#1f6feb', '#ffd33d'] as $accent) {
                $html = $this->html(new DocumentDesign($layout, $accent), $keys, $parameters);

                self::assertStringContainsString('<body class="layout-'.$layout->value.'">', $html);
                self::assertStringContainsString('<div class="watermark">BROUILLON</div>', $html, $layout->value);
                self::assertStringContainsString('--accent: '.$accent.';', $html);
                foreach ($keys as $key) {
                    $text = $translator->trans($key, array_combine(
                        array_map(static fn (string $name): string => "%$name%", array_keys($parameters[$key] ?? [])),
                        array_values($parameters[$key] ?? []),
                    ), 'fiscal', 'fr');
                    self::assertStringContainsString('<p class="mention">'.htmlspecialchars($text, \ENT_QUOTES).'</p>', $html, "$key in the $layout->value layout");
                }
                self::assertDoesNotMatchRegularExpression('/%[a-z_]+%/', $html);
            }
        }
    }

    public function testTheClassicDesignByDefaultPrintsInTheInkDocumentsAlwaysPrintedIn(): void
    {
        self::bootKernel();
        $html = $this->html(new DocumentDesign(), [], []);

        foreach (['<body class="layout-classic">', '--accent: #1f2328;', '--accent-text: #1f2328;', '--accent-ink: #ffffff;'] as $expected) {
            self::assertStringContainsString($expected, $html);
        }
    }

    public function testADepositInvoiceIsTitledAsOne(): void
    {
        self::bootKernel();
        $line = $this->invoice->getLines()[0];
        $this->invoice = Invoice::create($this->invoice->getCompany(), $this->invoice->getEstablishment(), $this->invoice->getCustomer(), new InvoiceHeader(), [new InvoiceLineDetails(null, 'Acompte de 30 % sur le devis DEV-2026-00001', '1', $line->getUnit(), '90', null, [$line->getTaxes()[0]->getTaxComponent()])], [], new \DateTimeImmutable('2026-10-07 09:00:00'), deposit: true);

        $html = $this->html(new DocumentDesign(), [], []);

        self::assertStringContainsString('<title>Facture d’acompte </title>', $html);
    }

    public function testALineDiscountIsPrintedAsItWasGivenAsARateOrAnAmount(): void
    {
        self::bootKernel();
        $line = $this->invoice->getLines()[0];
        $tax = [$line->getTaxes()[0]->getTaxComponent()];
        $this->invoice = Invoice::create($this->invoice->getCompany(), $this->invoice->getEstablishment(), $this->invoice->getCustomer(), new InvoiceHeader(), [
            new InvoiceLineDetails(null, 'Réglage', '2', $line->getUnit(), '150', '10', $tax),
            new InvoiceLineDetails(null, 'Pose', '1', $line->getUnit(), '80', null, $tax, discountAmount: '12.5'),
        ], [], new \DateTimeImmutable('2026-10-07 09:00:00'));

        $html = $this->html(new DocumentDesign(), [], []);

        self::assertMatchesRegularExpression('#<td class="number">10\s%</td>#u', $html, 'a rate with its sign');
        self::assertMatchesRegularExpression('#<td class="number">12,50</td>#u', $html, 'an amount as money is written');
    }

    public function testWhatTheDiscountsSaveIsPrintedUnderTheTotalsOnlyWhenGiven(): void
    {
        self::bootKernel();

        self::assertStringNotContainsString('data-testid="savings"', $this->html(new DocumentDesign(), [], []));
        self::assertMatchesRegularExpression('#<p data-testid="savings">Vous économisez 42,50 HT grâce aux remises\.</p>#u', $this->html(new DocumentDesign(), [], [], '42.500'));
    }

    public function testWhatTheLinesComeToInEachUnitIsPrintedUnderThemWithEachUnitsDecimals(): void
    {
        self::bootKernel();

        self::assertStringNotContainsString('data-testid="quantities"', $this->html(new DocumentDesign(), [], []));
        $html = $this->html(new DocumentDesign(), [], [], quantities: [new QuantityTotal('pièce', 0, '12'), new QuantityTotal('kg', 3, '3.5')]);
        self::assertMatchesRegularExpression('#data-testid="quantities">Quantités\x{00A0}: 12 pièce · 3,500 kg</p>#u', $html);
    }

    /**
     * A layout restyles the one content every document prints; it may not take any of it away. Its rules never hide,
     * fade or move anything, and never touch the watermark or a mention.
     */
    public function testTheLogoPrintsAtTheDesignsSizeInEveryLayoutKeptInProportionUnlessFreed(): void
    {
        foreach (DocumentLayout::cases() as $layout) {
            $html = $this->html(new DocumentDesign($layout, DocumentDesign::DEFAULT_ACCENT, 60, 25), [], []);
            self::assertStringContainsString('.logo { display: block; width: 60mm; height: 25mm; object-fit: contain;', $html, $layout->value);
            $freed = $this->html(new DocumentDesign($layout, DocumentDesign::DEFAULT_ACCENT, 60, 25, false), [], []);
            self::assertStringContainsString('.logo { display: block; width: 60mm; height: 25mm; object-fit: fill;', $freed, $layout->value);
            // No layout shrinks it on its own: the size is the company's.
            self::assertDoesNotMatchRegularExpression('/\.layout-[a-z]+ \.logo/', $html, $layout->value);
        }
    }

    public function testNoLayoutRuleCanTakeAnythingOffThePage(): void
    {
        $rules = array_filter(
            explode("\n", (string) file_get_contents(self::TEMPLATES.'/_document.css.twig')),
            static fn (string $line): bool => str_starts_with(trim($line), '.layout-'),
        );
        self::assertGreaterThanOrEqual(10, \count($rules), 'the layouts are found');
        foreach ($rules as $rule) {
            self::assertDoesNotMatchRegularExpression('/display:\s*none|visibility|opacity|position|transform|watermark|mention|font-size:\s*0|height:\s*0|overflow|clip|z-index|content:/i', $rule);
        }
    }

    /** @return array{list<string>, array<string, array<string, string>>} every mention of every preset, filled where it waits */
    private function everyMention(): array
    {
        $presets = static::getContainer()->get(FiscalPresets::class);
        $keys = [];
        foreach ($presets->keys() as $code) {
            $preset = $presets->get($code);
            $keys = [...$keys, ...$preset->invoiceMentions];
            foreach ([...$preset->customerTaxRegimes, ...$preset->companyVatRegimes] as $regime) {
                if (null !== $regime->mentionKey) {
                    $keys[] = $regime->mentionKey;
                }
            }
        }
        $keys = array_values(array_unique($keys));
        $values = ['rate' => '12 % l’an', 'reference' => 'article 261-4-4° du CGI'];
        self::assertSame(array_keys(InvoiceMentions::DATA), array_keys($values), 'every datum a mention may wait for is filled here');

        return [$keys, ['fiscal.mention.fr.late_payment' => ['rate' => $values['rate']], 'fiscal.mention.fr.exempt' => ['reference' => $values['reference']]]];
    }

    /** Every printed document names the seller through one place, which prints a legal form the name already ends with once. */
    public function testTheSellerIsNamedWithItsLegalFormOnceOnEveryDocument(): void
    {
        self::bootKernel();
        $this->invoice->getCompany()->reviseProfile(new CompanyProfile(legalName: 'Atelier Durand SAS', legalForm: 'SAS'));

        self::assertStringContainsString('<div class="company-name">Atelier Durand SAS</div>', $this->html(new DocumentDesign(), [], []));
        foreach (['invoice', 'quote', 'delivery_note', 'statement'] as $document) {
            $source = (string) file_get_contents(self::TEMPLATES.'/'.$document.'.html.twig');
            self::assertStringContainsString('{{ seller.printedName }}', $source, $document);
            self::assertStringNotContainsString('seller.legalForm', $source, $document);
        }
    }

    /**
     * @param list<string>                         $keys
     * @param array<string, array<string, string>> $parameters
     * @param list<QuantityTotal>                  $quantities
     */
    private function html(DocumentDesign $design, array $keys, array $parameters, ?string $savings = null, array $quantities = []): string
    {
        $template = static::getContainer()->get(InvoiceTemplate::class);
        $company = $this->invoice->getCompany();

        return $template->html(new InvoicePage(
            $this->invoice,
            $this->totals->figures($this->invoice),
            CustomerSnapshot::of($this->invoice->getCustomer()),
            SellerSnapshot::of($company, $this->invoice->getEstablishment()),
            InvoicePage::DRAFT,
            'fr',
            '',
            $keys,
            null,
            null,
            mentionParameters: $parameters,
            design: $design,
            savings: $savings,
            quantities: $quantities,
        ));
    }
}
