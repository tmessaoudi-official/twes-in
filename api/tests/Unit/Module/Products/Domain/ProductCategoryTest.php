<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Products\Domain;

use App\Module\Products\Domain\InvalidProductCategory;
use App\Module\Products\Domain\ProductCategory;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;

final class ProductCategoryTest extends TestCase
{
    private Company $company;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->now = new \DateTimeImmutable('2026-09-14 09:00:00');
    }

    public function testACategoryIsNamedAndMaySitUnderAnotherOfItsCompany(): void
    {
        $hardware = ProductCategory::create($this->company, '  Matériel ', null, $this->now);
        $laptops = ProductCategory::create($this->company, 'Portables', $hardware, $this->now);

        self::assertSame('Matériel', $hardware->getName());
        self::assertNull($hardware->getParent());
        self::assertSame($hardware, $laptops->getParent());
    }

    public function testANameIsOneToOneHundredTwentyCharacters(): void
    {
        foreach (['', '   ', str_repeat('a', ProductCategory::NAME_MAX + 1)] as $name) {
            try {
                ProductCategory::create($this->company, $name, null, $this->now);
                self::fail('refused: '.$name);
            } catch (InvalidProductCategory $refused) {
                self::assertSame('name', $refused->field);
            }
        }
        self::assertSame(ProductCategory::NAME_MAX, mb_strlen(ProductCategory::create($this->company, str_repeat('é', ProductCategory::NAME_MAX), null, $this->now)->getName()));
    }

    public function testAParentOfAnotherCompanyIsRefused(): void
    {
        $theirs = ProductCategory::create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'Matériel', null, $this->now);

        $this->expectExceptionObject(new InvalidProductCategory('parentId', 'A category sits under a category of its own company.'));
        ProductCategory::create($this->company, 'Portables', $theirs, $this->now);
    }

    public function testACategoryNeverSitsUnderItselfOrUnderOneOfItsOwnSubcategories(): void
    {
        $hardware = ProductCategory::create($this->company, 'Matériel', null, $this->now);
        $laptops = ProductCategory::create($this->company, 'Portables', $hardware, $this->now);
        $gaming = ProductCategory::create($this->company, 'Gaming', $laptops, $this->now);

        foreach ([$hardware, $laptops, $gaming] as $below) {
            try {
                $hardware->revise('Matériel', $below, $this->now);
                self::fail('refused under '.$below->getName());
            } catch (InvalidProductCategory $refused) {
                self::assertSame('parentId', $refused->field);
            }
        }
        self::assertSame($laptops, $gaming->getParent());
        self::assertNull($hardware->getParent(), 'a refused revision changes nothing');
    }

    public function testARevisionSaysWhetherAnythingChanged(): void
    {
        $hardware = ProductCategory::create($this->company, 'Matériel', null, $this->now);
        $software = ProductCategory::create($this->company, 'Logiciel', null, $this->now);
        $laptops = ProductCategory::create($this->company, 'Portables', $hardware, $this->now);

        self::assertSame([], $laptops->revise(' Portables ', $hardware, $this->now));
        self::assertSame(['parentId'], $laptops->revise('Portables', $software, $this->now));
        self::assertSame(['name', 'parentId'], $laptops->revise('Ordinateurs', null, $this->now));
        self::assertNull($laptops->getParent());
    }
}
