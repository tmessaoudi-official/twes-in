<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Application;

use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Domain\SettingChain;
use App\Settings\Domain\SettingDefinition;
use App\Settings\Domain\SettingLevel;
use PHPUnit\Framework\TestCase;

final class BusinessDefaultSettingsTest extends TestCase
{
    private SettingCatalog $catalog;

    protected function setUp(): void
    {
        $this->catalog = new SettingCatalog([new BusinessDefaultSettings()]);
    }

    public function testThePartiesChainCarriesTheDocumentDefaults(): void
    {
        self::assertSame(
            ['document.payment_terms_days', 'document.language', 'document.printed_notes', 'document.amount_in_words', 'credit.limit'],
            array_map(static fn (SettingDefinition $definition) => $definition->key, $this->catalog->ofChain(SettingChain::Parties)),
        );
        $terms = $this->definition('document.payment_terms_days');
        self::assertSame(30, $terms->default);
        self::assertNotNull($terms->refusal(366));
        self::assertNull($terms->refusal(0));
        // A customer group, a customer and a document override the company, as their screens arrive.
        self::assertSame([SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document], $terms->overridableAt);
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
