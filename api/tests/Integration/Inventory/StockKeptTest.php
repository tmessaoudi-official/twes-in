<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Integration\Inventory;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Infrastructure\Doctrine\StockKept;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Application\ReadSetting;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Whether stock is kept of a product, read in SQL where a list must be counted and paged by the database (the watch's
 * « Sous zéro », « Sorties sans stock »), answers what `KeepStock::tracked` answers in PHP for every product: goods
 * only, and `article.stock_tracking` as the product, else its category, else the company says, a value that is no
 * yes or no being skipped as the settings skip it.
 */
final class StockKeptTest extends KernelTestCase
{
    private const string KEY = 'article.stock_tracking';

    public function testTheSqlAnswersWhatTheStockSaysForEveryProduct(): void
    {
        self::bootKernel();
        $answers = [];
        foreach (['on' => true, 'off' => false, 'unset' => null] as $name => $atCompany) {
            $company = new Company('Quincaillerie '.$name, 'TN', 'TND', 'fr', 'Africa/Tunis');
            $this->em()->persist($company);
            $this->em()->flush();
            static::getContainer()->get(ProvisionCompany::class)->handle($company);
            $this->setting(SettingAddress::company($company), $atCompany);
            $products = $this->grid($company);
            $this->em()->flush();

            $keeps = StockKept::byCompany(static::getContainer()->get(ReadSetting::class), $company);
            $native = $this->em()->getConnection()->fetchAllKeyValue(
                'SELECT p.reference, '.StockKept::sql('p.kind', 'p.company_id', 'p.category_id', 'p.id', ':keeps').' FROM product p WHERE p.company_id = :company',
                ['keeps' => $keeps, 'company' => $company->getId()->toRfc4122()],
                ['keeps' => ParameterType::BOOLEAN],
            );
            /** @var list<array{reference: string}> $dql */
            $dql = $this->em()->createQuery('SELECT p.reference FROM '.Product::class.' p WHERE p.company = :company AND STOCK_KEPT(p.kind, IDENTITY(p.company), IDENTITY(p.category), p.id, :keeps) = true')
                ->setParameter('company', $company->getId(), 'uuid')->setParameter('keeps', $keeps, ParameterType::BOOLEAN)
                ->getArrayResult();
            $keptInDql = array_column($dql, 'reference');

            foreach ($products as $reference => $product) {
                $tracked = static::getContainer()->get(KeepStock::class)->tracked($product);
                $answers[$name.' '.$reference] = [$tracked, (bool) ($native[$reference] ?? null), \in_array($reference, $keptInDql, true)];
            }
        }

        $kept = array_keys(array_filter($answers, static fn (array $answer): bool => $answer[0]));
        self::assertCount(3 * (16 + 4), $answers, 'every product of the grid was read: goods in four categories, services in one');
        self::assertNotSame([], $kept, 'some are kept');
        self::assertNotSame(\count($answers), \count($kept), 'some are not');
        foreach ($answers as $product => [$tracked, $native, $dql]) {
            self::assertSame($tracked, $native, "$product: SQL");
            self::assertSame($tracked, $dql, "$product: DQL");
        }
    }

    /**
     * Each kind, with or without a category, under every value the category and the product may hold, a value that is
     * no yes or no among them.
     *
     * @return array<string, Product> by reference
     */
    private function grid(Company $company): array
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $company->getId());
        self::assertNotNull($unit);
        $now = new \DateTimeImmutable();
        $categories = [];
        foreach (['on' => true, 'off' => false, 'unset' => null] as $name => $value) {
            $category = ProductCategory::create($company, 'Rayon '.$name, null, $now);
            $this->em()->persist($category);
            $this->em()->flush();
            $this->setting(SettingAddress::productCategory($company, $category->getId()), $value);
            $categories[$name] = $category;
        }
        $categories['none'] = null;

        $products = [];
        foreach ([ProductKind::Goods, ProductKind::Service] as $kind) {
            foreach ($categories as $categoryName => $category) {
                foreach (['on' => true, 'off' => false, 'unset' => null, 'refused' => 'yes'] as $name => $value) {
                    $reference = strtoupper(substr($kind->value, 0, 1)).'-'.$categoryName.'-'.$name;
                    if (ProductKind::Service === $kind && 'unset' !== $categoryName) {
                        continue;
                    }
                    $product = Product::create($company, $reference, new ProductDetails($reference, null, $kind, '1'), $unit, $category, [], $now);
                    $this->em()->persist($product);
                    $this->em()->flush();
                    $this->setting(SettingAddress::product($company, $product->getId()), $value);
                    $products[$reference] = $product;
                }
            }
        }

        return $products;
    }

    /** A value stored as the settings store it; a value that is not yes or no is written past the engine's refusal. */
    private function setting(SettingAddress $address, bool|string|null $value): void
    {
        if (null === $value) {
            return;
        }
        $this->em()->persist(new Setting($address, self::KEY, $value, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
