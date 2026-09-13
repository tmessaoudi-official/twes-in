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
            ['document.payment_terms_days', 'document.language', 'document.printed_notes'],
            array_map(static fn (SettingDefinition $definition) => $definition->key, $this->catalog->ofChain(SettingChain::Parties)),
        );
        $terms = $this->definition('document.payment_terms_days');
        self::assertSame(30, $terms->default);
        self::assertNotNull($terms->refusal(366));
        self::assertNull($terms->refusal(0));
        // A customer group, a customer and a document override the company, as their screens arrive.
        self::assertSame([SettingLevel::Company, SettingLevel::CustomerGroup, SettingLevel::Customer, SettingLevel::Document], $terms->overridableAt);
    }

    public function testTheArticlesChainCarriesTheProductDefaults(): void
    {
        self::assertSame(
            ['article.default_unit', 'article.stock_tracking'],
            array_map(static fn (SettingDefinition $definition) => $definition->key, $this->catalog->ofChain(SettingChain::Articles)),
        );
        $unit = $this->definition('article.default_unit');
        self::assertSame('C62', $unit->default);
        self::assertNull($unit->refusal('KGM'));
        self::assertNotNull($unit->refusal('kilo'), 'a unit is a UN/ECE Recommendation 20 code');
        self::assertFalse($this->definition('article.stock_tracking')->default);
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
