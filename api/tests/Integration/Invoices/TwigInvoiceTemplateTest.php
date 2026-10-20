<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Invoices;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\QuantityTotal;
use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Fiscal\Domain\ValueObject\PrintedNames;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Customers\Domain\ValueObject\CustomerSnapshot;
use App\Module\Invoices\Application\InvoicePage;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Application\Print\InvoiceMentions;
use App\Module\Invoices\Application\Print\InvoiceTemplate;
use App\Module\Invoices\Domain\Model\Invoice;
use App\Module\Invoices\Domain\ValueObject\InvoiceFigures;
use App\Module\Invoices\Domain\ValueObject\InvoiceHeader;
use App\Module\Invoices\Domain\ValueObject\InvoiceIssue;
use App\Module\Invoices\Domain\ValueObject\InvoiceLineDetails;
use App\Shared\Domain\ValueObject\DocumentDesign;
use App\Shared\Domain\ValueObject\DocumentLayout;
use App\Shared\Domain\ValueObject\PrintSettings;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\ValueObject\CompanyProfile;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;
use App\Tests\Support\InMemoryEstablishments;
use App\Tests\Support\InMemoryNumberingSeries;
use App\Tests\Support\InMemoryTaxComponents;
use App\Tests\Support\InMemoryUnits;
use App\Tests\Support\KeptNames;
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

    public function testAChainedDocumentPrintsTheStartOfItsHashThoughItHasNoFooterAndADraftNone(): void
    {
        self::bootKernel();
        self::assertStringNotContainsString('data-testid="chain-fingerprint"', $this->html(new DocumentDesign(), [], []), 'a draft is in no chain');

        $now = new \DateTimeImmutable('2026-10-07 09:00:00');
        $this->invoice->issue(new InvoiceIssue('FAC-2026-00001', $now, 30, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto'), KeptNames::of($this->invoice)), fn (Invoice $issuing): InvoiceFigures => $this->totals->issued($issuing), $now);
        $this->invoice->seal(1, null);
        $hash = $this->invoice->getChainHash();
        self::assertNotNull($hash);
        self::assertNull($this->invoice->getFooter(), 'the company prints no footer text, and the fingerprint is printed all the same');

        $expected = implode(' ', str_split(substr($hash, 0, 16), 4));
        self::assertStringContainsString("<p class=\"fingerprint\" data-testid=\"chain-fingerprint\">Empreinte\u{a0}: $expected</p>", $this->html(new DocumentDesign(), [], [], watermark: null));
    }

    /**
     * An Arabic document reads right to left, and what it quotes in Latin letters or digits (a code, a number, an
     * address) is isolated, or the bidi algorithm reorders it: « +216 71 000 000 » would print its groups backwards.
     */
    public function testAnArabicInvoiceTurnsOverAndIsolatesWhatItQuotes(): void
    {
        self::bootKernel();

        $french = $this->html(new DocumentDesign(), [], []);
        $arabic = $this->html(new DocumentDesign(), [], [], language: 'ar');

        self::assertStringContainsString('<html lang="fr" dir="ltr">', $french);
        self::assertStringContainsString('<html lang="ar" dir="rtl">', $arabic);
        self::assertStringContainsString('<title>فاتورة </title>', $arabic);
        self::assertStringContainsString('<bdi>CLI-0007</bdi>', $arabic);
        self::assertStringContainsString('<bdi>Réglage du tour</bdi>', $arabic);
        self::assertStringContainsString('"Noto Sans Arabic"', $arabic);
        self::assertDoesNotMatchRegularExpression('/%[a-z_]+%/', $arabic);
        self::assertStringContainsString('<td>Unité</td>', $french);
        self::assertStringContainsString('<td>وحدة</td>', $arabic, 'a unit prints its name in the document\'s language');
    }

    /**
     * « Cancelled » across the page agrees with the document it stamps: an invoice is feminine in French and in Arabic,
     * where a quote or a delivery note sharing the plain word is not.
     */
    public function testACancelledInvoiceIsStampedInTheFeminine(): void
    {
        self::bootKernel();

        self::assertStringContainsString('<div class="watermark">ANNULÉE</div>', $this->html(new DocumentDesign(), [], [], watermark: InvoicePage::CANCELLED));
        self::assertStringContainsString('<div class="watermark">CANCELLED</div>', $this->html(new DocumentDesign(), [], [], watermark: InvoicePage::CANCELLED, language: 'en'));
        self::assertStringContainsString('<div class="watermark">ملغاة</div>', $this->html(new DocumentDesign(), [], [], watermark: InvoicePage::CANCELLED, language: 'ar'));
    }

    /**
     * A rated tax prints its name in the document's language with the rate the document froze, which its name may already
     * say; a name saying another rate (a tax renamed since) never hides the rate charged.
     */
    public function testARatedTaxPrintsItsNameInTheDocumentsLanguageWithTheRateItFroze(): void
    {
        self::bootKernel();
        $space = "\u{a0}";

        $named = $this->html(new DocumentDesign(), [], [], language: 'ar', taxNames: ['TVA20' => 'أ.ق.م 20 %']);
        self::assertStringContainsString('<td><bdi>أ.ق.م 20 %</bdi></td>', $named, 'the line names its tax');
        self::assertStringContainsString('<td><bdi>أ.ق.م 20 %</bdi> <span class="muted">', $named, 'the summary row names its tax');
        self::assertStringNotContainsString('TVA20', $named);

        $renamed = $this->html(new DocumentDesign(), [], [], taxNames: ['TVA20' => 'TVA 13 %']);
        self::assertStringContainsString("<bdi>TVA 13 % (20{$space}%)</bdi>", $renamed);

        $unnamed = $this->html(new DocumentDesign(), [], []);
        self::assertStringContainsString("<bdi>TVA20 (20{$space}%)</bdi>", $unnamed, 'a code where no name is known');
    }

    /**
     * A line's taxes are each named in the document's language, set apart as the language lists them, and the
     * withholding is named with the rate it froze; with no name known, the withholding still says what it is.
     */
    public function testALinesTaxesAndTheWithholdingPrintTheirNamesInTheDocumentsLanguage(): void
    {
        self::bootKernel();
        $space = "\u{a0}";
        $now = new \DateTimeImmutable('2026-10-19 09:00:00');
        $units = new InMemoryUnits();
        $taxes = new InMemoryTaxComponents();
        $establishments = new InMemoryEstablishments();
        $company = new Company('Quincaillerie Ben Salah', 'TN', 'TND', 'fr', 'Africa/Tunis');
        new ProvisionCompany(ShippedFiscalPresets::presets(), $taxes, $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), new \Symfony\Component\Clock\MockClock($now))->handle($company);
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Société Mansour'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $unit = $units->ofCodeInCompany('C62', $company->getId());
        $levy = $taxes->ofCodeInCompany('FODEC', $company->getId());
        $vat = $taxes->ofCodeInCompany('TVA19', $company->getId());
        $withholding = $taxes->ofCodeInCompany('RS1', $company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($levy);
        self::assertNotNull($vat);
        self::assertNotNull($withholding);
        $invoice = Invoice::create($company, $establishments->ofCompany($company->getId())[0], $customer, new InvoiceHeader(), [new InvoiceLineDetails(null, 'Perceuse', '1', $unit, '2000', null, [$levy, $vat])], [$withholding], $now);
        $figures = $this->totals->figures($invoice);
        self::assertNotSame([], $figures->withholdings, 'the invoice withholds something');
        $template = static::getContainer()->get(InvoiceTemplate::class);
        $page = static fn (string $language, bool $named): string => $template->html(new InvoicePage($invoice, $figures, CustomerSnapshot::of($customer), SellerSnapshot::of($company, $invoice->getEstablishment()), InvoicePage::DRAFT, $language, '', [], null, null, names: $named ? new DocumentNames($taxes)->now($company, $language, [$unit]) : KeptNames::of($invoice, $language)));

        $arabic = $page('ar', true);
        self::assertStringContainsString('<td><bdi>FODEC 1 %</bdi>، <bdi>أ.ق.م 19 %</bdi></td>', $arabic, 'the line, listed as Arabic lists');
        self::assertStringContainsString('<td><bdi>الخصم من المورد 1 %</bdi> <span class="muted">', $arabic, 'the withholding');

        $french = $page('fr', false);
        self::assertStringContainsString("<td><bdi>FODEC (1{$space}%)</bdi>, <bdi>TVA19 (19{$space}%)</bdi></td>", $french);
        self::assertStringContainsString("<td>Retenue à la source <bdi>RS1 (1{$space}%)</bdi> <span class=\"muted\">", $french, 'a withholding with no name known');
    }

    public function testAStampPrintsItsNameInTheDocumentsLanguageAndItsCodeWhenItHasNone(): void
    {
        self::bootKernel();
        $now = new \DateTimeImmutable('2026-10-19 09:00:00');
        $units = new InMemoryUnits();
        $taxes = new InMemoryTaxComponents();
        $establishments = new InMemoryEstablishments();
        $company = new Company('Quincaillerie Ben Salah', 'TN', 'TND', 'fr', 'Africa/Tunis');
        new ProvisionCompany(ShippedFiscalPresets::presets(), $taxes, $units, $establishments, new InMemoryNumberingSeries(), ShippedFiscalPresets::scales(), new \Symfony\Component\Clock\MockClock($now))->handle($company);
        $customer = Customer::create($company, 'CLI-0001', new CustomerProfile(CustomerKind::Company, 'Société Mansour'), null, new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now), [], $now);
        $unit = $units->ofCodeInCompany('C62', $company->getId());
        $stamp = $taxes->ofCodeInCompany('TIMBRE', $company->getId());
        self::assertNotNull($unit);
        self::assertNotNull($stamp);
        $invoice = Invoice::create($company, $establishments->ofCompany($company->getId())[0], $customer, new InvoiceHeader(), [new InvoiceLineDetails(null, 'Vis', '2', $unit, '1', null, [])], [$stamp], $now);
        $figures = $this->totals->figures($invoice);
        $template = static::getContainer()->get(InvoiceTemplate::class);

        self::assertStringContainsString('<td><bdi>معلوم الطابع الجبائي</bdi></td>', $template->html(new InvoicePage($invoice, $figures, CustomerSnapshot::of($customer), SellerSnapshot::of($company, $invoice->getEstablishment()), InvoicePage::DRAFT, 'ar', '', [], null, null, names: new PrintedNames(KeptNames::of($invoice, 'ar')->units, ['TIMBRE' => 'معلوم الطابع الجبائي']))));
        self::assertStringContainsString('<td><bdi>TIMBRE</bdi></td>', $template->html(new InvoicePage($invoice, $figures, CustomerSnapshot::of($customer), SellerSnapshot::of($company, $invoice->getEstablishment()), InvoicePage::DRAFT, 'ar', '', [], null, null, names: KeptNames::of($invoice, 'ar'))));
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

    public function testACreditNotePrintsTheDocumentDiscountItGivesBackThoughItTakesNoneOfItsOwn(): void
    {
        self::bootKernel();
        self::assertStringNotContainsString('<td>Remise</td>', $this->html(new DocumentDesign(), [], []), 'a document with no discount prints no discount row');
        $now = new \DateTimeImmutable('2026-10-07 09:00:00');
        $line = $this->invoice->getLines()[0];
        $invoice = Invoice::create($this->invoice->getCompany(), $this->invoice->getEstablishment(), $this->invoice->getCustomer(), new InvoiceHeader(discountAmount: '30'), [
            new InvoiceLineDetails(null, 'Réglage du tour', '2', $line->getUnit(), '150', null, [$line->getTaxes()[0]->getTaxComponent()]),
        ], [], $now);
        $invoice->issue(new InvoiceIssue('FAC-2026-00001', $now, 30, 'fr', [], null, null, null, new PrintSettings('', 'auto', 'auto'), KeptNames::of($invoice)), fn (Invoice $issuing): InvoiceFigures => $this->totals->issued($issuing), $now);
        $this->invoice = Invoice::creditNoteFor($invoice, 'Retour', $now);
        self::assertNull($this->invoice->getHeader()->discountAmount, 'the credit note has no discount of its own');

        $html = $this->html(new DocumentDesign(), [], []);

        self::assertMatchesRegularExpression('#<td>Remise</td><td class="number">30,00</td>#u', $html, 'what it gives back of the invoice\'s discount is printed, against its negative lines as on the screen');
        self::assertMatchesRegularExpression('#<td>Total HT après remise</td><td class="number">-270,00</td>#u', $html, 'and what the lines come to after it, the base its VAT is worked on');
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
     * @param list<string>                                   $keys
     * @param array<string, array<string, string>>           $parameters
     * @param list<QuantityTotal>                            $quantities
     * @param InvoicePage::DRAFT|InvoicePage::CANCELLED|null $watermark
     * @param array<string, string>                          $taxNames
     */
    private function html(DocumentDesign $design, array $keys, array $parameters, ?string $savings = null, array $quantities = [], ?string $watermark = InvoicePage::DRAFT, string $language = 'fr', array $taxNames = []): string
    {
        $template = static::getContainer()->get(InvoiceTemplate::class);
        $company = $this->invoice->getCompany();

        return $template->html(new InvoicePage(
            $this->invoice,
            $this->totals->figures($this->invoice),
            CustomerSnapshot::of($this->invoice->getCustomer()),
            SellerSnapshot::of($company, $this->invoice->getEstablishment()),
            $watermark,
            $language,
            '',
            $keys,
            null,
            null,
            mentionParameters: $parameters,
            design: $design,
            savings: $savings,
            quantities: $quantities,
            names: new PrintedNames(KeptNames::of($this->invoice, $language)->units, $taxNames),
        ));
    }
}
