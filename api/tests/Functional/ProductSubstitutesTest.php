<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Domain\Unit;
use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductDetails;
use App\Module\Products\Domain\ProductKind;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * Products the business says replace one another (docs/SPEC.md § 7): a group is a name its products carry, its members
 * are found whatever the case it was typed with, and only active products of the company are offered.
 */
final class ProductSubstitutesTest extends ApiTestCase
{
    private Company $company;
    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        $this->unit = Unit::create($this->company, 'C62', 'Unité', 0, 0, new \DateTimeImmutable());
        $this->em()->persist($this->unit);
    }

    public function testTheOtherActiveMembersOfAProductsGroupAreItsSubstitutesWhateverTheCaseOfTheName(): void
    {
        $azerty = $this->product('KB-1', 'Clavier AZERTY filaire', 'Clavier AZERTY');
        $this->product('KB-2', 'Clavier AZERTY sans fil', 'clavier azerty ');
        $this->product('KB-3', 'Clavier AZERTY rétro', 'CLAVIER AZERTY', active: false);
        $this->product('KB-4', 'Clavier QWERTY', 'Clavier QWERTY');
        $alone = $this->product('MS-1', 'Souris', null);
        $this->em()->flush();
        $this->signedIn(['product.read']);

        $this->getJson($this->path($azerty).'/substitutes');

        self::assertResponseIsSuccessful();
        self::assertSame(['KB-2'], array_column($this->jsonList(), 'reference'), 'the product itself, an inactive member and another group are left out');
        self::assertSame(['Clavier AZERTY sans fil', true], [$this->jsonList()[0]['name'], $this->jsonList()[0]['isActive']]);

        $this->getJson($this->path($alone).'/substitutes');
        self::assertSame([], $this->jsonList(), 'a product in no group has no substitutes');
    }

    public function testTheGroupsListedAreTheCompanysOwnWithHowManyProductsEachHolds(): void
    {
        $this->product('KB-1', 'Clavier A', 'Clavier AZERTY');
        $this->product('KB-2', 'Clavier B', 'clavier azerty');
        $this->product('KB-4', 'Clavier QWERTY', 'Clavier QWERTY');
        $this->product('MS-1', 'Souris', null);
        $globex = $this->createCompany('Globex');
        $theirUnit = Unit::create($globex, 'C62', 'Unité', 0, 0, new \DateTimeImmutable());
        $this->em()->persist($theirUnit);
        $this->em()->persist(Product::create($globex, 'KB-9', new ProductDetails('Clavier', null, ProductKind::Goods, '1', null, 'Clavier AZERTY'), $theirUnit, null, [], new \DateTimeImmutable()));
        $this->em()->flush();
        $this->signedIn(['product.read']);

        $this->getJson('/api/companies/'.$this->company->getId()->toRfc4122().'/product-substitution-groups');

        self::assertResponseIsSuccessful();
        self::assertSame([['Clavier AZERTY', 2], ['Clavier QWERTY', 1]], array_map(static fn (array $row): array => [$row['name'], $row['products']], $this->jsonList()));
    }

    public function testAProductsGroupIsWrittenAndReadBackThroughItsOwnResource(): void
    {
        $this->em()->flush();
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson('/api/companies/'.$this->company->getId()->toRfc4122().'/products', [
            'reference' => 'KB-1',
            'name' => 'Clavier',
            'kind' => 'goods',
            'unitId' => $this->unit->getId()->toRfc4122(),
            'unitPriceNet' => '10',
            'substitutionGroup' => '  Clavier AZERTY ',
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        self::assertSame('Clavier AZERTY', $this->json()['substitutionGroup']);
        $id = $this->stringAt($this->json(), 'id');

        $body = ['reference' => 'KB-1', 'name' => 'Clavier', 'kind' => 'goods', 'unitId' => $this->unit->getId()->toRfc4122(), 'unitPriceNet' => '10'];
        $this->sendJson('PUT', $this->path($id), [...$body, 'substitutionGroup' => null]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['substitutionGroup']);
        self::assertSame('{"fields": ["substitutionGroup"]}', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'product.revised'"));

        $this->sendJson('PUT', $this->path($id), [...$body, 'substitutionGroup' => str_repeat('a', 81)]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('substitutionGroup', (string) $this->client->getResponse()->getContent());
    }

    public function testItNeedsTheReadRightAndAnotherCompanysProductIsNotFound(): void
    {
        $azerty = $this->product('KB-1', 'Clavier', 'Clavier AZERTY');
        $this->em()->flush();
        $this->createUser('nobody@twes.local', 'password-1234', $this->company, [], 'nobody');
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['product.read'], 'reader');
        $this->login('nobody@twes.local', 'password-1234');
        $this->getJson($this->path($azerty).'/substitutes');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->login('reader@twes.local', 'password-1234');
        $this->getJson($this->path('0192c3a4-0000-7000-8000-000000000000').'/substitutes');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions, string $who = 'sales'): void
    {
        $this->createUser($who.'@twes.local', 'password-1234', $this->company, $permissions, $who);
        $this->login($who.'@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function product(string $reference, string $name, ?string $group, bool $active = true): string
    {
        $product = Product::create($this->company, $reference, new ProductDetails($name, null, ProductKind::Goods, '10', null, $group), $this->unit, null, [], new \DateTimeImmutable());
        if (!$active) {
            $product->revise($reference, new ProductDetails($name, null, ProductKind::Goods, '10', null, $group), $this->unit, null, [], false, new \DateTimeImmutable());
        }
        $this->em()->persist($product);

        return $product->getId()->toRfc4122();
    }

    private function path(string $id): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/products/'.$id;
    }
}
