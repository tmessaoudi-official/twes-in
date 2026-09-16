<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Application;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Application\ManageProductCategories;
use App\Module\Products\Application\ProductCategoryInUse;
use App\Module\Products\Application\ProductCategoryNameTaken;
use App\Module\Products\Application\ProductCategoryNotFound;
use App\Module\Products\Domain\InvalidProductCategory;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Settings\Application\ForgetSettings;
use App\Settings\Domain\Setting;
use App\Settings\Domain\SettingAddress;
use App\Tenancy\Domain\Company;
use App\Tests\Support\FakeTransactions;
use App\Tests\Support\InMemoryAuditTrail;
use App\Tests\Support\InMemoryProductCategories;
use App\Tests\Support\InMemoryProducts;
use App\Tests\Support\InMemorySettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

final class ManageProductCategoriesTest extends TestCase
{
    private InMemoryProductCategories $categories;
    private InMemoryProducts $products;
    private InMemorySettings $settings;
    private InMemoryAuditTrail $audit;
    private ManageProductCategories $manage;
    private Company $company;

    protected function setUp(): void
    {
        $this->categories = new InMemoryProductCategories();
        $this->products = new InMemoryProducts();
        $this->settings = new InMemorySettings();
        $transactions = new FakeTransactions();
        $this->audit = new InMemoryAuditTrail($transactions);
        $this->manage = new ManageProductCategories($this->categories, $this->products, new ForgetSettings($this->settings), $this->audit, new MockClock('2026-09-14 09:00:00'), $transactions);
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }

    public function testACategoryIsCreatedUnderAnotherAndAuditedWithoutItsName(): void
    {
        $actor = Uuid::v7();
        $hardware = $this->manage->create($this->company, 'Matériel', null, $actor);
        $laptops = $this->manage->create($this->company, 'Portables', $hardware->getId(), $actor);

        self::assertSame([$hardware, $laptops], $this->manage->list($this->company));
        self::assertSame($hardware, $laptops->getParent());
        self::assertSame(1, $this->manage->childCount($hardware));
        self::assertSame(0, $this->manage->productCount($hardware));
        $entry = $this->audit->entries[1];
        self::assertSame([ManageProductCategories::ENTITY_TYPE, ManageProductCategories::CREATED, []], [$entry->entityType, $entry->action, $entry->changes]);
        self::assertSame($actor, $entry->actorUserId);
        self::assertTrue($laptops->getId()->equals($entry->entityId));
        self::assertTrue($this->company->getId()->equals($entry->companyId));
    }

    public function testAParentNotOfTheCompanyIsRefusedNamingTheField(): void
    {
        $theirs = $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'Matériel', null, null);

        try {
            $this->manage->create($this->company, 'Portables', $theirs->getId(), null);
            self::fail("Another company's category became a parent.");
        } catch (InvalidProductCategory $refused) {
            self::assertSame('parentId', $refused->field);
        }
        self::assertSame([], $this->manage->list($this->company));
    }

    public function testANameAnotherCategoryOfTheCompanyHasIsRefused(): void
    {
        $this->manage->create($this->company, 'Matériel', null, null);
        $software = $this->manage->create($this->company, 'Logiciel', null, null);
        $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'Services', null, null);

        $this->manage->revise($this->company, $software->getId(), 'Logiciel', null, null);
        $this->manage->create($this->company, 'Services', null, null);
        try {
            $this->manage->revise($this->company, $software->getId(), ' Matériel ', null, null);
            self::fail('A category was renamed to a name another category has.');
        } catch (ProductCategoryNameTaken) {
        }
        $this->expectException(ProductCategoryNameTaken::class);
        $this->manage->create($this->company, 'Matériel', null, null);
    }

    public function testARevisionMovesACategoryAndIsAuditedWithTheFieldsItChanged(): void
    {
        $hardware = $this->manage->create($this->company, 'Matériel', null, null);
        $laptops = $this->manage->create($this->company, 'Portables', null, null);

        $this->manage->revise($this->company, $laptops->getId(), 'Portables', null, null);
        self::assertCount(2, $this->audit->entries, 'nothing changed, nothing audited');

        $this->manage->revise($this->company, $laptops->getId(), 'Portables', $hardware->getId(), null);
        self::assertSame([ManageProductCategories::REVISED, ['fields' => ['parentId']]], [$this->audit->entries[2]->action, $this->audit->entries[2]->changes]);
        self::assertSame($hardware, $laptops->getParent());

        $this->expectException(InvalidProductCategory::class);
        $this->manage->revise($this->company, $hardware->getId(), 'Matériel', $laptops->getId(), null);
    }

    public function testACategoryWithASubcategoryOrAProductIsKept(): void
    {
        $hardware = $this->manage->create($this->company, 'Matériel', null, null);
        $laptops = $this->manage->create($this->company, 'Portables', $hardware->getId(), null);
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '1250'), Unit::create($this->company, 'C62', 'pièce', 0, 0, new \DateTimeImmutable()), $laptops, [], new \DateTimeImmutable());
        $this->products->save($product);

        foreach ([$hardware, $laptops] as $kept) {
            try {
                $this->manage->delete($this->company, $kept->getId(), null);
                self::fail($kept->getName().' was deleted.');
            } catch (ProductCategoryInUse) {
            }
        }
        self::assertCount(2, $this->manage->list($this->company));
        self::assertSame(1, $this->manage->productCount($laptops));

        $product->revise('ART-001', $product->getDetails(), $product->getUnit(), null, [], true, new \DateTimeImmutable());
        $this->manage->delete($this->company, $laptops->getId(), null);
        $this->manage->delete($this->company, $hardware->getId(), null);

        self::assertSame([], $this->manage->list($this->company));
        self::assertSame([ManageProductCategories::DELETED, ManageProductCategories::DELETED], [$this->audit->entries[2]->action, $this->audit->entries[3]->action]);
    }

    public function testDeletingACategoryForgetsWhatItsSettingsSaid(): void
    {
        $hardware = $this->manage->create($this->company, 'Matériel', null, null);
        $this->settings->save(new Setting(SettingAddress::productCategory($this->company, $hardware->getId()), 'article.stock_tracking', true, new \DateTimeImmutable()));
        $this->settings->save(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', false, new \DateTimeImmutable()));

        $this->manage->delete($this->company, $hardware->getId(), null);

        self::assertCount(1, $this->settings->settings);
        self::assertFalse($this->settings->settings[0]->getValue());
    }

    public function testAnotherCompanysCategoryIsNotFound(): void
    {
        $theirs = $this->manage->create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'Matériel', null, null);

        try {
            $this->manage->revise($this->company, $theirs->getId(), 'Mine now', null, null);
            self::fail("Another company's category was revised.");
        } catch (ProductCategoryNotFound) {
        }
        $this->expectException(ProductCategoryNotFound::class);
        $this->manage->delete($this->company, $theirs->getId(), null);
    }
}
