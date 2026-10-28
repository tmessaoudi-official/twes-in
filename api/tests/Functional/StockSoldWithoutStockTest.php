<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Inventory\Domain\Model\StockLocation;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Module\Inventory\Domain\ValueObject\StockLocationKind;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Sorties sans stock » (Decisions Log 2026-10-28 00:13): the movements list narrows to the sales and deliveries that
 * left their place below zero and that no count of the product at that place has followed since, and says it of each
 * row; a count clears them. A sale is never blocked (2026-10-21 18:24), so this is how the shop finds what to count.
 */
final class StockSoldWithoutStockTest extends ApiTestCase
{
    private Company $company;
    private string $visId;
    private string $gantId;
    private string $siteId;
    private string $shelfId;
    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $piece = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($piece);
        $now = new \DateTimeImmutable();
        $vis = Product::create($this->company, 'VIS', new ProductDetails('Vis 6x40', null, ProductKind::Goods, '1'), $piece, null, [], $now);
        $gant = Product::create($this->company, 'GANT', new ProductDetails('Gants', null, ProductKind::Goods, '5'), $piece, null, [], $now);
        $this->em()->persist($vis);
        $this->em()->persist($gant);
        $this->visId = $vis->getId()->toRfc4122();
        $this->gantId = $gant->getId()->toRfc4122();
        $this->em()->persist(new Setting(SettingAddress::company($this->company), 'article.stock_tracking', true, $now));
        $this->em()->flush();
        $this->start = new \DateTimeImmutable('-2 hours');
    }

    public function testASaleThatLeftItsPlaceBelowZeroIsListedUntilACountThereFollowsIt(): void
    {
        $this->signedIn(['company.read', 'product.read', 'stock.read', 'stock.write']);
        $this->places();
        // VIS at the site: 2 in, 5 out (to -3: short), 10 in, 3 out (to 4: covered), 6 out (to -2: short).
        $this->moved(StockMovement::receipt($this->product($this->visId), $this->place($this->siteId), '2', null, $this->at(1)));
        $first = $this->moved(StockMovement::delivery($this->product($this->visId), $this->place($this->siteId), '5', Uuid::v7(), $this->at(2)));
        $this->moved(StockMovement::receipt($this->product($this->visId), $this->place($this->siteId), '10', null, $this->at(3)));
        $covered = $this->moved(StockMovement::sale($this->product($this->visId), $this->place($this->siteId), '3', Uuid::v7(), $this->at(4)));
        $second = $this->moved(StockMovement::sale($this->product($this->visId), $this->place($this->siteId), '6', Uuid::v7(), $this->at(5)));
        // GANT sold from the shelf with nothing there; a loss is no sale, whatever it leaves.
        $gant = $this->moved(StockMovement::delivery($this->product($this->gantId), $this->place($this->shelfId), '1', Uuid::v7(), $this->at(6)));

        self::assertSame([$gant, $second, $first], $this->listed('yes'), 'newest first, as the history is read');
        $flags = $this->flags('');
        self::assertTrue($flags[$first]);
        self::assertTrue($flags[$second]);
        self::assertFalse($flags[$covered], 'a sale the stock covered is none');
        self::assertCount(6, $flags);
        self::assertSame(3, \count(array_filter($flags)), 'every row says whether it is one, filtered or not');
        self::assertNotContains($first, $this->listed('no'));
        self::assertContains($covered, $this->listed('no'));

        // Counted at the site: VIS's two short sales are cleared, GANT's on the shelf is not.
        $this->postJson($this->company().'/stock-movements', ['operation' => 'count', 'productId' => $this->visId, 'locationId' => $this->siteId, 'quantity' => '4']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        self::assertSame([$gant], $this->listed('yes'));

        // A count of GANT at the site says nothing of the shelf.
        $this->postJson($this->company().'/stock-movements', ['operation' => 'count', 'productId' => $this->gantId, 'locationId' => $this->siteId, 'quantity' => '0']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED, (string) $this->client->getResponse()->getContent());
        self::assertSame([$gant], $this->listed('yes'));

        // A sale after the count that takes the place below zero again is listed again.
        $again = $this->moved(StockMovement::delivery($this->product($this->visId), $this->place($this->siteId), '7', Uuid::v7(), new \DateTimeImmutable('+1 minute')));
        self::assertSame([$again, $gant], $this->listed('yes'));
    }

    public function testASaleOfAProductWhoseStockIsNoLongerKeptIsNoneToCount(): void
    {
        // A count of a product whose stock is not kept is refused (`not_stocked`), so its short sale, listed, would stay
        // for ever: off for the product, for its category, for the company, or turned into a service, it is no longer one.
        $this->signedIn(['company.read', 'product.read', 'stock.read', 'stock.write']);
        $this->places();
        $vis = $this->moved(StockMovement::delivery($this->product($this->visId), $this->place($this->siteId), '2', Uuid::v7(), $this->at(1)));
        $gant = $this->moved(StockMovement::sale($this->product($this->gantId), $this->place($this->siteId), '1', Uuid::v7(), $this->at(2)));
        self::assertSame([$gant, $vis], $this->listed('yes'), 'both, while stock is kept of them');

        $connection = $this->em()->getConnection();
        $this->em()->persist(new Setting(SettingAddress::product($this->managed(), Uuid::fromString($this->visId)), 'article.stock_tracking', false, new \DateTimeImmutable()));
        $this->em()->flush();
        self::assertSame([$gant], $this->listed('yes'), 'off for the product');
        self::assertContains($vis, $this->listed('no'));
        self::assertFalse($this->flags('')[$vis], 'and its row says so');

        $connection->executeStatement("UPDATE product SET kind = 'service' WHERE id = ?", [$this->gantId]);
        self::assertSame([], $this->listed('yes'), 'turned into a service');
        $connection->executeStatement("UPDATE product SET kind = 'goods' WHERE id = ?", [$this->gantId]);
        self::assertSame([$gant], $this->listed('yes'), 'goods again');

        $tools = ProductCategory::create($this->managed(), 'Outillage', null, new \DateTimeImmutable());
        $this->em()->persist($tools);
        $this->em()->flush();
        $this->em()->persist(new Setting(SettingAddress::productCategory($this->managed(), $tools->getId()), 'article.stock_tracking', false, new \DateTimeImmutable()));
        $this->em()->flush();
        $connection->executeStatement('UPDATE product SET category_id = ? WHERE id = ?', [$tools->getId()->toRfc4122(), $this->gantId]);
        self::assertSame([], $this->listed('yes'), 'off for its category');
        $connection->executeStatement('UPDATE product SET category_id = NULL WHERE id = ?', [$this->gantId]);

        // Off for the company, on for the product: kept, so listed; the other product's own « off » still wins.
        $connection->executeStatement("UPDATE setting SET value = 'false' WHERE level = 'company' AND level_id = ? AND key = 'article.stock_tracking'", [$this->company->getId()->toRfc4122()]);
        self::assertSame([], $this->listed('yes'), 'off for the company');
        $this->em()->persist(new Setting(SettingAddress::product($this->managed(), Uuid::fromString($this->gantId)), 'article.stock_tracking', true, new \DateTimeImmutable()));
        $this->em()->flush();
        self::assertSame([$gant], $this->listed('yes'), 'on for the product alone');
        self::assertTrue($this->flags('')[$gant]);
    }

    public function testTheFilterTakesOnlyYesOrNo(): void
    {
        $this->signedIn(['company.read', 'stock.read']);
        $this->getJson($this->company().'/stock-movements?withoutStock=maybe');
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY, 'a filter value it does not know is refused, never read as no filter');
    }

    /** The default location, which the first read lays out, and a shelf under it. */
    private function places(): void
    {
        $this->getJson($this->company().'/stock-locations');
        self::assertResponseIsSuccessful();
        $site = $this->em()->find(StockLocation::class, $this->stringAt($this->jsonList()[0], 'id'));
        self::assertInstanceOf(StockLocation::class, $site);
        $this->siteId = $site->getId()->toRfc4122();
        $shelf = StockLocation::create($site->getEstablishment(), $site, StockLocationKind::Rack, 'E2', 'Étagère 2', new \DateTimeImmutable());
        $this->em()->persist($shelf);
        $this->em()->flush();
        $this->shelfId = $shelf->getId()->toRfc4122();
    }

    /** The product as the entity manager knows it now: the test client reboots the kernel between requests. */
    private function product(string $id): Product
    {
        $product = $this->em()->find(Product::class, $id);
        self::assertInstanceOf(Product::class, $product);

        return $product;
    }

    private function managed(): Company
    {
        $company = $this->em()->find(Company::class, $this->company->getId());
        self::assertInstanceOf(Company::class, $company);

        return $company;
    }

    private function place(string $id): StockLocation
    {
        $place = $this->em()->find(StockLocation::class, $id);
        self::assertInstanceOf(StockLocation::class, $place);

        return $place;
    }

    /** Saves a movement made the way its use case makes it, and says its id. */
    private function moved(StockMovement $movement): string
    {
        $this->em()->persist($movement);
        $this->em()->flush();

        return $movement->getId()->toRfc4122();
    }

    /** A moment of the story, minutes apart, so the order of the movements is the order they are told in. */
    private function at(int $minute): \DateTimeImmutable
    {
        return $this->start->modify("+$minute minutes");
    }

    /** @return list<string> the ids of the movements the filter keeps, as the list orders them */
    private function listed(string $answer): array
    {
        $this->getJson($this->company().'/stock-movements?withoutStock='.$answer);
        self::assertResponseIsSuccessful();

        return array_map(fn (array $row): string => $this->stringAt($row, 'id'), $this->jsonList());
    }

    /** @return array<string, bool> each movement's own word on it, by id */
    private function flags(string $answer): array
    {
        $this->getJson($this->company().'/stock-movements'.('' === $answer ? '' : '?withoutStock='.$answer));
        self::assertResponseIsSuccessful();
        $flags = [];
        foreach ($this->jsonList() as $row) {
            self::assertIsBool($row['withoutStock'] ?? null, 'every row says it');
            $flags[$this->stringAt($row, 'id')] = $row['withoutStock'];
        }

        return $flags;
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('owner@twes.local', 'password-1234', $this->company, $permissions, 'keeper');
        $this->login('owner@twes.local', 'password-1234');
    }

    private function company(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }
}
