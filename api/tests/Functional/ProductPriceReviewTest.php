<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Infrastructure\Effects\EffectsAnswer;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * A receipt that moves a product's cost under a price that stays moves its margin: the product shows the move to whoever
 * reads costs, « À surveiller » lists it, and the price is adjusted to keep the margin or kept, never moved in silence.
 */
final class ProductPriceReviewTest extends ApiTestCase
{
    private Company $company;
    private string $productId;

    public function testAReceiptMovingTheCostShowsTheMoveWithThePriceThatKeepsTheMargin(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->receive('1000');
        $this->getJson($this->path());
        self::assertNull($this->review(), 'a first cost moves no margin');

        $this->receive('1100');

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertSame(['previousCost' => '1000.0000', 'cost' => '1100.0000', 'price' => '1250.0000', 'suggestedPrice' => '1375.000', 'previousMargin' => '20.0', 'margin' => '12.0'], $this->review());
        self::assertSame('1250.0000', $this->json()['unitPriceNet'] ?? null, 'the price is the company\'s to move');
        $this->getJson($this->path('watch/product.margin_moved'));
        self::assertResponseIsSuccessful();
        self::assertSame([$this->productId], array_column($this->jsonList(), 'subjectId'));
        self::assertSame(['product' => 'Perceuse', 'reference' => 'ART-001', 'previousCost' => '1000.0000', 'cost' => '1100.0000', 'price' => '1250.0000', 'previousMargin' => '20.0', 'margin' => '12.0'], $this->jsonList()[0]['params'] ?? null);

        $this->receive('1200');
        $this->getJson($this->path());
        $review = $this->review();
        self::assertIsArray($review);
        self::assertSame(['1000.0000', '1200.0000', '1500.000'], [$review['previousCost'] ?? null, $review['cost'] ?? null, $review['suggestedPrice'] ?? null], 'the whole drift since the price was set');
    }

    /** A product merged away keeps no review of its own: « À surveiller » no longer lists it. */
    public function testAProductMergedAwayLeavesTheWatch(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->stepUp('password-1234');
        $this->receive('1000');
        $this->receive('1100');
        $this->postJson($this->path('products'), ['reference' => 'ART-002', 'name' => 'Perceuse 2', 'description' => null, 'kind' => 'goods', 'unitId' => $this->pieceId(), 'unitPriceNet' => '1250', 'costPrice' => null, 'categoryId' => null, 'defaultTaxComponentIds' => [], 'isActive' => true, 'customFields' => []]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $kept = $this->stringAt($this->json(), 'id');
        $this->getJson($this->path('watch/product.margin_moved'));
        self::assertSame([$this->productId], array_column($this->jsonList(), 'subjectId'));

        $this->postJson($this->path('record-merges'), ['subject' => 'product', 'keptId' => $kept, 'ids' => [$this->productId]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        $this->getJson($this->path('watch/product.margin_moved'));
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->jsonList());
    }

    public function testAdjustingSetsThePriceThatKeepsTheMarginAndKeepingLeavesIt(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->receive('1000');
        $this->receive('1100');

        $this->settle('adjust', $this->storedVersion('product', $this->productId));

        self::assertResponseIsSuccessful();
        self::assertSame(['1375.0000', null], [$this->json()['unitPriceNet'] ?? null, $this->review()]);
        self::assertSame($this->storedVersion('product', $this->productId), $this->json()['version'] ?? null, 'the answer carries the version it left');
        self::assertContains(['unitPriceNet'], $this->revisedFields());
        $this->getJson($this->path('watch/product.margin_moved'));
        self::assertSame([], $this->jsonList(), 'handled, so no longer watched');

        $this->receive('1210');
        $this->settle('keep', $this->storedVersion('product', $this->productId));
        self::assertResponseIsSuccessful();
        self::assertSame(['1375.0000', null], [$this->json()['unitPriceNet'] ?? null, $this->review()]);
        self::assertContains(['priceReview'], $this->revisedFields(), 'recorded, so every open screen hears it');

        $this->settle('keep', $this->storedVersion('product', $this->productId));
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, 'nothing left to review');
    }

    public function testASettlementOverAVersionSavedSinceOrAnUnknownDecisionIsRefused(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->receive('1000');
        $this->receive('1100');
        $version = $this->storedVersion('product', $this->productId);

        $this->settle('lower', $version);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->settle('adjust', $version - 1);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('1250.0000', $this->em()->getConnection()->fetchOne('SELECT unit_price_net FROM product WHERE id = ?', [$this->productId]));
    }

    public function testTheReviewIsACostReadersOnly(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->receive('1000');
        $this->receive('1100');

        $this->createUser('clerk@twes.local', 'password-1234', $this->company(), ['company.read', 'product.read', 'product.write'], 'counter');
        $this->login('clerk@twes.local', 'password-1234');
        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        self::assertNull($this->review(), 'a margin is a cost read');
        $this->settle('adjust', $this->storedVersion('product', $this->productId));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path('watch/product.margin_moved'));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->createUser('reader@twes.local', 'password-1234', $this->company(), ['company.read', 'product.read', 'product.cost.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');
        $this->settle('adjust', $this->storedVersion('product', $this->productId));
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'reading costs is not writing prices');

        $other = $this->createCompany('Globex');
        $this->login('stock@twes.local', 'password-1234');
        $this->sendJson('POST', '/api/companies/'.$other->getId()->toRfc4122().'/products/'.$this->productId.'/price-review', ['decision' => 'adjust', 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, 'another company');
    }

    public function testACompanyKeepingItsPricesIsOfferedNothing(): void
    {
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.price_on_cost_change', 'keep', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['company.read', 'product.read', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->receive('1000');
        $this->receive('1100');

        $this->getJson($this->path());
        self::assertSame(['1100.0000', '1250.0000', null], [$this->json()['costPrice'] ?? null, $this->json()['unitPriceNet'] ?? null, $this->review()]);
    }

    public function testACompanyFollowingTheMarginHasItsPriceMovedWithTheCost(): void
    {
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.price_on_cost_change', 'follow', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['company.read', 'product.read', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->receive('1000');
        $this->receive('1100');

        $this->getJson($this->path());
        self::assertSame(['1100.0000', '1375.0000', null], [$this->json()['costPrice'] ?? null, $this->json()['unitPriceNet'] ?? null, $this->review()]);
        self::assertContains(['costPrice', 'unitPriceNet'], $this->revisedFields());
    }

    /**
     * The receipt that moved the price or the margin says so in its own answer, whichever screen sent it, and a receipt
     * at the same cost moved nothing and says nothing, though a review is still open.
     */
    public function testTheReceiptThatMovedThePriceOrTheMarginSaysItInItsAnswer(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.cost.read', 'stock.read', 'stock.write']);
        $this->receive('1000');
        self::assertSame([], $this->effects(), 'a first cost moves no margin');

        $this->receive('1100');
        self::assertSame([['kind' => 'product.margin_moved', 'id' => $this->productId, 'params' => []]], $this->effects());
        $this->receive('1100');
        self::assertSame([], $this->effects());

        $this->em()->persist(new Setting(SettingAddress::company($this->company()), 'article.price_on_cost_change', 'follow', new \DateTimeImmutable()));
        $this->em()->flush();
        $this->receive('1200');
        self::assertSame([['kind' => 'product.price_followed', 'id' => $this->productId, 'params' => ['previousPrice' => '1250.0000']]], $this->effects(), 'the price it had, for « Annuler »');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $product = Product::create($this->company, 'ART-001', new ProductDetails('Perceuse', null, ProductKind::Goods, '1250'), $piece, null, [], $now);
        $this->em()->persist($product);
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->productId = $product->getId()->toRfc4122();
    }

    private function receive(string $unitCost): void
    {
        $this->getJson($this->path('stock-locations'));
        self::assertResponseIsSuccessful();
        $site = $this->stringAt($this->jsonList()[0], 'id');
        $this->postJson($this->path('stock-movements'), ['operation' => 'receive', 'productId' => $this->productId, 'locationId' => $site, 'quantity' => '2', 'unitCost' => $unitCost, 'applyCost' => 'last']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
    }

    /** @return list<mixed> what the last answer said the request did beyond its subject */
    private function effects(): array
    {
        $header = $this->client->getResponse()->headers->get(EffectsAnswer::HEADER);
        if (null === $header) {
            return [];
        }
        $effects = json_decode($header, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsList($effects);

        return $effects;
    }

    private function settle(string $decision, int $version): void
    {
        $this->postJson($this->path().'/price-review', ['decision' => $decision, 'version' => $version]);
    }

    /**
     * The fields each revision of the product named; a set, since revisions within one second share their moment.
     *
     * @return list<mixed>
     */
    private function revisedFields(): array
    {
        $rows = $this->em()->getConnection()->fetchFirstColumn("SELECT changes FROM audit_log WHERE entity_id = ? AND action = 'product.revised'", [$this->productId]);

        return array_map(static function (mixed $changes): mixed {
            $decoded = \is_string($changes) ? json_decode($changes, true, flags: \JSON_THROW_ON_ERROR) : null;

            return \is_array($decoded) ? ($decoded['fields'] ?? null) : null;
        }, $rows);
    }

    /** The product's review as the last answer gave it, which must name one, null included. */
    private function review(): mixed
    {
        $product = $this->json();
        self::assertArrayHasKey('priceReview', $product);

        return $product['priceReview'];
    }

    private function company(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertNotNull($company);

        return $company;
    }

    private function path(?string $resource = null): string
    {
        $company = '/api/companies/'.$this->company->getId()->toRfc4122().'/';

        return null === $resource ? $company.'products/'.$this->productId : $company.$resource;
    }

    private function pieceId(): string
    {
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);

        return $piece->getId()->toRfc4122();
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('stock@twes.local', 'password-1234', $this->company(), $permissions, 'member');
        $this->login('stock@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }
}
