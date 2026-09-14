<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Settings\Application;

use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\ForgetSettings;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use App\Settings\Application\SettingContext;
use App\Settings\Application\SettingLevelRefused;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Settings\Domain\SettingLevel;
use App\Tenancy\Domain\Company;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

/** The articles chain below the company: what a product category says, then what a product says (docs/SPEC.md § 3 Settings). */
final class ArticleLevelsTest extends TestCase
{
    private const string TRACKING = 'article.stock_tracking';
    private const string UNIT = 'article.default_unit';

    private InMemorySettings $settings;
    private ResolveSettings $resolve;
    private ChangeSettings $change;
    private Company $company;
    private Uuid $categoryId;
    private Uuid $productId;

    protected function setUp(): void
    {
        $this->settings = new InMemorySettings();
        $catalog = new SettingCatalog([new BusinessDefaultSettings()]);
        $this->resolve = new ResolveSettings($catalog, $this->settings);
        $this->change = new ChangeSettings($catalog, $this->settings, $this->resolve, new InMemoryAuditTrail(), new MockClock('2026-09-14 09:00:00'));
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->categoryId = Uuid::v7();
        $this->productId = Uuid::v7();
    }

    public function testAProductInheritsItsCategorysValueAndMayOverrideIt(): void
    {
        $category = new SettingContext($this->company, productCategoryId: $this->categoryId);
        $this->change->change($category, self::TRACKING, SettingLevel::ProductCategory, true, null);
        $this->change->change($category, self::UNIT, SettingLevel::ProductCategory, 'HUR', null);

        $product = new SettingContext($this->company, productCategoryId: $this->categoryId, productId: $this->productId);
        $inherited = $this->resolve->one($product, self::TRACKING);
        self::assertSame([true, SettingLevel::ProductCategory], [$inherited->value, $inherited->source]);

        $own = $this->change->change($product, self::UNIT, SettingLevel::Product, 'KGM', null);
        self::assertSame(['KGM', SettingLevel::Product], [$own->value, $own->source]);
        self::assertSame(['product_category' => 'HUR', 'product' => 'KGM'], $own->explicit);

        $uncategorised = new SettingContext($this->company, productId: Uuid::v7());
        self::assertSame([false, 'C62'], [$this->resolve->one($uncategorised, self::TRACKING)->value, $this->resolve->one($uncategorised, self::UNIT)->value]);
    }

    public function testAProductsOwnValueIsItsAloneAndStaysInItsCompany(): void
    {
        $this->change->change(new SettingContext($this->company, productId: $this->productId), self::UNIT, SettingLevel::Product, 'KGM', null);

        $sibling = new SettingContext($this->company, productId: Uuid::v7());
        $elsewhere = new SettingContext(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), productId: $this->productId);

        self::assertSame('C62', $this->resolve->one($sibling, self::UNIT)->value);
        self::assertSame('C62', $this->resolve->one($elsewhere, self::UNIT)->value);
    }

    public function testACategorysValueStaysInItsCompany(): void
    {
        $this->settings->save(new Setting(SettingAddress::productCategory($this->company, $this->categoryId), self::TRACKING, true, new \DateTimeImmutable()));

        $elsewhere = new SettingContext(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), productCategoryId: $this->categoryId);

        self::assertFalse($this->resolve->one($elsewhere, self::TRACKING)->value);
    }

    public function testALevelWithoutItsSubjectIsRefused(): void
    {
        $this->expectException(SettingLevelRefused::class);
        $this->change->change(new SettingContext($this->company, productCategoryId: $this->categoryId), self::UNIT, SettingLevel::Product, 'KGM', null);
    }

    public function testForgettingACategoryRemovesEveryValueStoredForIt(): void
    {
        $category = new SettingContext($this->company, productCategoryId: $this->categoryId);
        $this->change->change($category, self::TRACKING, SettingLevel::ProductCategory, true, null);
        $this->change->change($category, self::UNIT, SettingLevel::ProductCategory, 'HUR', null);
        $this->change->change($category, self::UNIT, SettingLevel::Company, 'KGM', null);

        (new ForgetSettings($this->settings))->at(SettingAddress::productCategory($this->company, $this->categoryId));

        self::assertCount(1, $this->settings->settings);
        self::assertSame('KGM', $this->resolve->one($category, self::UNIT)->value);
    }
}
