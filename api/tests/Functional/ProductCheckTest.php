<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\TaxComponentRepository;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Application\ProductCheckScopes;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Module\Products\Domain\ValueObject\Barcode;
use App\Module\Products\Domain\ValueObject\BarcodeLine;
use App\Module\Products\Domain\ValueObject\BarcodeRole;
use App\Module\Products\Domain\ValueObject\ProductCheckReason;
use App\Module\Products\Domain\ValueObject\ProductCheckScope;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Module\Products\Domain\ValueObject\ProductSearch;
use App\Module\Products\Infrastructure\Watch\ProductCheckWatch;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * « À vérifier » on products: the list narrows to what lacks something exactly as each product says it of itself, and
 * never tells a reader without product.cost.read that a cost is missing, by a reason or by a product listed for it alone.
 */
final class ProductCheckTest extends ApiTestCase
{
    private Company $company;

    /** @var array<string, string> each fixture's id by its reference */
    private array $ids = [];

    public function testTheDatabaseNarrowsToWhatEachProductSaysOfItself(): void
    {
        $repository = static::getContainer()->get(ProductRepository::class);
        $checked = 0;
        foreach ([true, false] as $keepsStock) {
            foreach ([true, false] as $chargesVat) {
                foreach ([true, false] as $seesCosts) {
                    $scope = new ProductCheckScope($keepsStock, $chargesVat, $seesCosts);
                    $all = $repository->search($this->company->getId(), new ProductSearch(), new PageRequest(1, 100))->items;
                    $expected = static fn (callable $keep): array => self::references(array_values(array_filter($all, $keep)));
                    $found = fn (ProductSearch $search): array => self::references($repository->search($this->company->getId(), $search->withCheckScope($scope), new PageRequest(1, 100))->items);

                    self::assertSame($expected(static fn (Product $p): bool => $p->getCheckMark()->marked || [] !== $p->checkReasons($scope)), $found(new ProductSearch(toCheck: true)));
                    self::assertSame($expected(static fn (Product $p): bool => !$p->getCheckMark()->marked && [] === $p->checkReasons($scope)), $found(new ProductSearch(toCheck: false)));
                    foreach ($scope->reasons() as $reason) {
                        $expectedForReason = $expected(static fn (Product $p): bool => \in_array($reason, $p->checkReasons($scope), true));
                        self::assertSame($expectedForReason, $found(new ProductSearch(checkReasons: [$reason])), $reason->value);
                        $checked += \count($expectedForReason);
                    }
                }
            }
        }
        self::assertGreaterThan(40, $checked, 'every reason found products to compare');
    }

    public function testAReaderWithoutCostsIsNeverToldACostIsMissing(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.cost.read']);
        $this->getJson($this->path('products?toCheck=true'));
        self::assertResponseIsSuccessful();
        self::assertContains('NO-COST', array_column($this->jsonList(), 'reference'));
        $this->getJson($this->path('products/'.$this->ids['NO-COST']));
        self::assertSame(['cost_missing'], $this->json()['checkReasons'] ?? null);
        $this->getJson($this->path('product-options'));
        self::assertContains('cost_missing', $this->arrayAt($this->json(), 'checkReasons'));

        $this->createUser('clerk@twes.local', 'password-1234', $this->company(), ['company.read', 'product.read'], 'counter');
        $this->login('clerk@twes.local', 'password-1234');
        $this->getJson($this->path('products?toCheck=true'));
        self::assertResponseIsSuccessful();
        self::assertNotContains('NO-COST', array_column($this->jsonList(), 'reference'), 'listed for its cost alone, it would tell the cost is missing');
        $this->getJson($this->path('products?toCheck=false'));
        self::assertContains('NO-COST', array_column($this->jsonList(), 'reference'));
        $this->getJson($this->path('products/'.$this->ids['NO-COST']));
        self::assertSame([], $this->json()['checkReasons'] ?? null);
        $this->getJson($this->path('products?checkReason=cost_missing'));
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'refused, as an empty answer would still say something');
        $this->getJson($this->path('product-options'));
        self::assertNotContains('cost_missing', $this->arrayAt($this->json(), 'checkReasons'), 'a list offers only what it may be told');
    }

    public function testTheWatchListsTheActiveProductsToCheckWithoutEverSayingACost(): void
    {
        $this->signedIn(['company.read', 'product.read']);
        $this->getJson($this->path('watch/'.ProductCheckWatch::TO_CHECK));
        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();

        $leftOut = static::getContainer()->getParameter('app.watch.product_check_left_out');
        $scope = static::getContainer()->get(ProductCheckScopes::class)->of($this->company(), false)
            ->without(...array_map(ProductCheckReason::from(...), $leftOut));
        $expected = [];
        foreach (static::getContainer()->get(ProductRepository::class)->search($this->company->getId(), new ProductSearch(active: true), new PageRequest(1, 100))->items as $product) {
            if ($product->getCheckMark()->marked || [] !== $product->checkReasons($scope)) {
                $expected[] = $product->getId()->toRfc4122();
            }
        }
        sort($expected);
        $listed = array_column($rows, 'subjectId');
        sort($listed);
        self::assertSame($expected, $listed);
        self::assertNotContains($this->ids['NO-COST'], $listed, 'listed for its cost alone, it would tell the cost is missing');
        self::assertNotContains($this->ids['RETIRED'], $listed, 'a product switched off is sold no more');

        $params = array_column($rows, 'params', 'subjectId');
        self::assertSame(['product' => 'MARKED', 'reference' => 'MARKED', 'reasons' => '', 'marked' => 1, 'note' => 'à relire'], $params[$this->ids['MARKED']] ?? null);
        self::assertSame(['product' => 'BARE', 'reference' => 'BARE', 'reasons' => 'price_missing,vendor_missing,vat_missing,barcode_missing,category_missing', 'marked' => 0, 'note' => ''], $params[$this->ids['BARE']] ?? null);
    }

    public function testTheHomeLeavesOutWhatOnlyClearsProductByProductWhileTheListKeepsIt(): void
    {
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        $vat = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $this->company->getId());
        self::assertNotNull($piece);
        self::assertNotNull($vat);
        $vendor = Vendor::create($this->company, 'F-002', new VendorProfile('Comaf'), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        foreach (['NO-BARCODE', 'NO-CATEGORY', 'NEITHER'] as $reference) {
            $category = 'NO-BARCODE' === $reference ? ProductCategory::create($this->company, 'Outillage', null, new \DateTimeImmutable()) : null;
            if (null !== $category) {
                $this->em()->persist($category);
            }
            $product = Product::create($this->company, $reference, new ProductDetails($reference, null, ProductKind::Goods, '10', '5'), $piece, $category, [$vat->getId()], new \DateTimeImmutable());
            $product->replaceVendors([$vendor], new \DateTimeImmutable());
            if ('NO-CATEGORY' === $reference) {
                $product->replaceBarcodes([new BarcodeLine(BarcodeRole::Internal, new Barcode('CHK-NC'), 1)], new \DateTimeImmutable());
            }
            $this->em()->persist($product);
            $this->ids[$reference] = $product->getId()->toRfc4122();
        }
        $this->em()->flush();

        $this->signedIn(['company.read', 'product.read']);
        $this->getJson($this->path('watch/'.ProductCheckWatch::TO_CHECK));
        self::assertResponseIsSuccessful();
        $rows = $this->jsonList();
        $listed = array_column($rows, 'subjectId');
        foreach (['NO-BARCODE', 'NO-CATEGORY', 'NEITHER'] as $reference) {
            self::assertNotContains($this->ids[$reference], $listed, $reference.': a barcode or a category is added product by product, never a matter for the home');
        }
        self::assertContains($this->ids['BARE'], $listed, 'lacking a price, it still is');
        $bare = array_column($rows, 'params', 'subjectId')[$this->ids['BARE']] ?? null;
        self::assertIsArray($bare);
        self::assertSame('price_missing,vendor_missing,vat_missing,barcode_missing,category_missing', $bare['reasons'] ?? null, 'a product listed names everything it lacks');

        $this->getJson($this->path('products?toCheck=true'));
        $references = array_column($this->jsonList(), 'reference');
        foreach (['NO-BARCODE', 'NO-CATEGORY', 'NEITHER'] as $reference) {
            self::assertContains($reference, $references, $reference.': the products list still finds it');
        }
        $this->getJson($this->path('products?checkReason=barcode_missing'));
        self::assertContains('NO-BARCODE', array_column($this->jsonList(), 'reference'));
    }

    public function testAPersonMarksAProductWithANoteAndSaysItsPriceOfNothingIsMeant(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write', 'product.cost.read']);
        $id = $this->ids['BARE'];
        $this->getJson($this->path('products/'.$id));
        $product = $this->json();
        self::assertSame(['price_missing', 'vendor_missing', 'vat_missing', 'cost_missing', 'barcode_missing', 'category_missing'], $product['checkReasons'] ?? null);

        $this->sendJson('PUT', $this->path('products/'.$id), [...self::written($product), 'toCheck' => true, 'checkNote' => 'prix à demander', 'zeroPriceIntended' => true]);
        self::assertResponseIsSuccessful();
        $saved = $this->json();
        self::assertSame([true, 'prix à demander', true], [$saved['toCheck'] ?? null, $saved['checkNote'] ?? null, $saved['zeroPriceIntended'] ?? null]);
        self::assertSame(['vendor_missing', 'vat_missing', 'cost_missing', 'barcode_missing', 'category_missing'], $saved['checkReasons'] ?? null);
        self::assertGreaterThan($product['version'] ?? 0, $saved['version'] ?? 0, 'a mark is a change another editor is told of');

        $this->sendJson('PUT', $this->path('products/'.$id), [...self::written($saved), 'checkNote' => str_repeat('a', CheckMark::NOTE_MAX + 1)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $vat = static::getContainer()->get(TaxComponentRepository::class)->ofCodeInCompany('TVA19', $this->company->getId());
        self::assertNotNull($vat);
        $now = new \DateTimeImmutable();
        $category = ProductCategory::create($this->company, 'Éclairage', null, $now);
        $vendor = Vendor::create($this->company, 'F-001', new VendorProfile('Sotupa'), $now);
        $this->em()->persist($category);
        $this->em()->persist($vendor);

        $code = 1;
        $make = function (string $reference, ProductKind $kind, string $price, ?string $cost, bool $complete) use ($piece, $vat, $category, $vendor, $now, &$code): Product {
            $product = Product::create($this->company, $reference, new ProductDetails($reference, null, $kind, $price, $cost), $piece, $complete ? $category : null, $complete ? [$vat->getId()] : [], $now);
            if ($complete && ProductKind::Goods === $kind) {
                $product->replaceVendors([$vendor], $now);
                $product->replaceBarcodes([new BarcodeLine(BarcodeRole::Internal, new Barcode('CHK-'.$code++), 1)], $now);
            }
            $this->em()->persist($product);
            $this->ids[$reference] = $product->getId()->toRfc4122();

            return $product;
        };
        $make('BARE', ProductKind::Goods, '0', null, false);
        $make('DONE', ProductKind::Goods, '10', '5', true);
        $make('NO-COST', ProductKind::Goods, '10', null, true);
        $make('MARKED', ProductKind::Goods, '10', '5', true)->reviseCheck(new CheckMark(true, 'à relire'), false, $now);
        $make('MEANT-FREE', ProductKind::Goods, '0', '5', true)->reviseCheck(new CheckMark(false), true, $now);
        $make('SERVICE', ProductKind::Service, '0', null, false);
        $make('DONE-SERVICE', ProductKind::Service, '30', null, true);
        $toCount = $make('TO-COUNT', ProductKind::Goods, '10', '5', true);
        $toCount->awaitCount();
        $toCount->awaitUnitConfirmation();
        $make('NOT-REACHED', ProductKind::Service, '8.3167', null, true)->priceReached(false, $now);
        $make('UNTAXED', ProductKind::Goods, '10', '5', true)->revise('UNTAXED', new ProductDetails('UNTAXED', null, ProductKind::Goods, '10', '5'), $piece, $category, [], true, $now);
        $make('RETIRED', ProductKind::Goods, '0', null, false)->revise('RETIRED', new ProductDetails('RETIRED', null, ProductKind::Goods, '0'), $piece, null, [], false, $now);
        $this->em()->flush();
    }

    /**
     * @param list<Product> $products
     *
     * @return list<string> sorted
     */
    private static function references(array $products): array
    {
        $references = array_map(static fn (Product $product): string => $product->getReference(), $products);
        sort($references);

        return $references;
    }

    /**
     * What a form sends back of a product it read: no id, as a PUT names its product by its address, and nothing only read.
     *
     * @param array<array-key, mixed> $product
     *
     * @return array<string, mixed>
     */
    private static function written(array $product): array
    {
        $written = [];
        foreach ($product as $field => $value) {
            if (\is_string($field) && !\in_array($field, ['id', 'checkReasons', 'barcodes', 'priceReview', 'mainPhotoId', 'mergedInto'], true)) {
                $written[$field] = $value;
            }
        }

        return $written;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company(), $permissions, 'member');
        $this->login('owner@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function path(string $resource): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$resource;
    }
}
