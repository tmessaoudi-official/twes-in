<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Application;

use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\ValueObject\SettingChain;
use App\Settings\Domain\ValueObject\SettingDefinition;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Domain\ValueObject\DocumentDesign;
use App\Shared\Domain\ValueObject\DocumentLayout;
use App\Shared\Domain\ValueObject\DocumentWatermark;
use App\Shared\Domain\ValueObject\WatermarkIntensity;
use App\Shared\Domain\ValueObject\WatermarkSpread;
use PHPUnit\Framework\TestCase;

final class BusinessDefaultSettingsTest extends TestCase
{
    private SettingCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new SettingCatalog([new BusinessDefaultSettings(\App\Tests\Support\ShippedLanguages::catalogue())]);
    }

    public function testThePartiesChainCarriesTheDocumentDefaults(): void
    {
        self::assertSame(
            ['document.payment_terms_days', 'document.language', 'document.printed_notes', 'document.how_to_pay', 'document.amount_in_words', 'document.late_payment_rate', 'document.exemption_reference', 'document.layout', 'document.accent', 'document.watermark_intensity', 'document.watermark_copies', 'document.watermark_spread', 'document.logo_width', 'document.logo_height', 'document.logo_keep_proportions', 'document.paid_stamp', 'document.savings_line', 'document.names_as_issued', 'credit.limit'],
            array_map(static fn (SettingDefinition $definition) => $definition->key, $this->catalog->ofChain(SettingChain::Parties)),
        );
        $terms = $this->definition('document.payment_terms_days');
        self::assertSame(30, $terms->default);
        self::assertNotNull($terms->refusal(366));
        self::assertNull($terms->refusal(0));
        // A customer group, a customer and a document override the company, as their screens arrive.
        self::assertSame([SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document], $terms->overridableAt);
    }

    public function testADocumentsDesignOffersEveryBuiltInLayoutAndIsTheCompanysAlone(): void
    {
        $layout = $this->definition('document.layout');
        $accent = $this->definition('document.accent');

        self::assertSame(array_map(static fn (DocumentLayout $each): string => $each->value, DocumentLayout::cases()), $layout->choices);
        self::assertSame([DocumentLayout::Classic->value, DocumentDesign::DEFAULT_ACCENT], [$layout->default, $accent->default]);
        self::assertSame([[SettingLevel::Company], [SettingLevel::Company]], [$layout->overridableAt, $accent->overridableAt]);
        self::assertNotNull($accent->refusal('red;}body{display:none'));
    }

    /** Written out for the labels gate; held here to the watermark's own cases, its defaults today's look. */
    public function testTheWatermarkIsTheCompanysInTheCasesItPrintsInDefaultingToTheLookDocumentsAlwaysHad(): void
    {
        $intensity = $this->definition('document.watermark_intensity');
        $copies = $this->definition('document.watermark_copies');
        $spread = $this->definition('document.watermark_spread');
        $default = new DocumentWatermark();

        self::assertSame(array_map(static fn (WatermarkIntensity $each): string => $each->value, WatermarkIntensity::cases()), $intensity->choices);
        self::assertSame(array_map(strval(...), DocumentWatermark::COPIES), $copies->choices);
        self::assertSame(array_map(static fn (WatermarkSpread $each): string => $each->value, WatermarkSpread::cases()), $spread->choices);
        self::assertSame([$default->intensity->value, (string) $default->copies, $default->spread->value], [$intensity->default, $copies->default, $spread->default]);
        foreach ([$intensity, $copies, $spread] as $each) {
            self::assertSame([SettingLevel::Company], $each->overridableAt, $each->key);
        }
        self::assertNotNull($copies->refusal('2'));
    }

    public function testTheLogosRoomIsTheCompanysInMillimetresWithinWhatAPageHolds(): void
    {
        $width = $this->definition('document.logo_width');
        $height = $this->definition('document.logo_height');

        self::assertSame([DocumentDesign::DEFAULT_LOGO_WIDTH_MM, DocumentDesign::DEFAULT_LOGO_HEIGHT_MM], [$width->default, $height->default]);
        self::assertSame([[SettingLevel::Company], [SettingLevel::Company]], [$width->overridableAt, $height->overridableAt]);
        self::assertSame([DocumentDesign::LOGO_WIDTH_MM_MIN, DocumentDesign::LOGO_WIDTH_MM_MAX], [$width->min, $width->max]);
        self::assertSame([DocumentDesign::LOGO_HEIGHT_MM_MIN, DocumentDesign::LOGO_HEIGHT_MM_MAX], [$height->min, $height->max]);
        self::assertNotNull($width->refusal(DocumentDesign::LOGO_WIDTH_MM_MAX + 1));
        self::assertNull($height->refusal(DocumentDesign::LOGO_HEIGHT_MM_MIN));
        $locked = $this->definition('document.logo_keep_proportions');
        self::assertSame([true, [SettingLevel::Company]], [$locked->default, $locked->overridableAt]);
    }

    public function testACreditLimitIsAMoneyAmountWhereZeroMeansNoLimitAndOnlyAPartyHasOne(): void
    {
        $limit = $this->definition('credit.limit');
        self::assertSame('0', $limit->default, 'no limit until one is set');
        self::assertNull($limit->refusal('0'));
        self::assertNull($limit->refusal('15000.500'));
        self::assertNotNull($limit->refusal('-1'), 'a limit is never negative');
        self::assertNotNull($limit->refusal(1500), 'money travels as text');
        // A document has no limit of its own: the limit is about what the customer owes, not about one invoice.
        self::assertSame([SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer], $limit->overridableAt);
    }

    public function testTheArticlesChainCarriesTheProductDefaults(): void
    {
        self::assertSame(
            ['article.default_unit', 'article.stock_tracking', 'article.traceability', 'article.show_price_excl_tax'],
            array_map(static fn (SettingDefinition $definition) => $definition->key, $this->catalog->ofChain(SettingChain::Articles)),
        );
        $unit = $this->definition('article.default_unit');
        self::assertSame('C62', $unit->default);
        self::assertNull($unit->refusal('KGM'));
        self::assertNotNull($unit->refusal('kilo'), 'a unit is a UN/ECE Recommendation 20 code');
        self::assertFalse($this->definition('article.stock_tracking')->default);
        $shown = $this->definition('article.show_price_excl_tax');
        self::assertFalse($shown->default, 'what faces a customer shows the price with tax alone unless the company says otherwise');
        self::assertSame([SettingLevel::Company], $shown->overridableAt);
        $traceability = $this->definition('article.traceability');
        self::assertSame('none', $traceability->default);
        self::assertSame([SettingLevel::Company, SettingLevel::ProductCategory], $traceability->overridableAt, 'a product keeps its own value in its own column');
        self::assertNull($traceability->refusal('serial'));
        self::assertNotNull($traceability->refusal('batch'));
    }

    public function testNoBusinessDefaultIsAPersonalChoice(): void
    {
        foreach ([SettingChain::Parties, SettingChain::Articles] as $chain) {
            foreach ($this->catalog->ofChain($chain) as $definition) {
                self::assertFalse($definition->allows(SettingLevel::User), $definition->key);
                self::assertTrue($definition->allows(SettingLevel::Company), $definition->key);
            }
        }
    }

    private function definition(string $key): SettingDefinition
    {
        return $this->catalog->definitionOf($key) ?? throw new \LogicException("$key is not declared");
    }
}
