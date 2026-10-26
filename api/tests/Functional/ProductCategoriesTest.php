<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Domain\Model\Unit;
use App\Module\Products\Domain\Model\Product;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\ValueObject\ProductDetails;
use App\Module\Products\Domain\ValueObject\ProductKind;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;

final class ProductCategoriesTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
    }

    public function testAWriterCreatesNestsRevisesAndDeletesCategories(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->postJson($this->path(), ['name' => 'Matériel']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $hardware = $this->json();
        self::assertSame([null, 0, 0], [$hardware['parentId'], $hardware['productCount'], $hardware['childCount']]);
        $hardwareId = $this->stringAt($hardware, 'id');

        $this->postJson($this->path(), ['name' => 'Portables', 'parentId' => $hardwareId]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $laptopsId = $this->stringAt($this->json(), 'id');
        self::assertSame($hardwareId, $this->json()['parentId']);

        $this->getJson($this->path());
        self::assertSame([['Matériel', null, 1], ['Portables', $hardwareId, 0]], array_map(static fn (array $row) => [$row['name'], $row['parentId'], $row['childCount']], $this->jsonList()));

        $this->sendJson('PUT', $this->path($laptopsId), ['name' => 'Ordinateurs portables', 'parentId' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertNull($this->json()['parentId']);

        $this->postJson($this->removalsPath(), ['subject' => 'product_category', 'ids' => [$laptopsId]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $this->getJson($this->path());
        self::assertSame(['Matériel'], array_column($this->jsonList(), 'name'));

        $actions = $this->em()->getConnection()->fetchFirstColumn("SELECT action FROM audit_log WHERE entity_type = 'product_category' ORDER BY at, action");
        self::assertEqualsCanonicalizing(['product_category.created', 'product_category.created', 'product_category.revised', 'product_category.deleted'], $actions);
        self::assertSame('{"fields": ["name", "parentId"]}', $this->em()->getConnection()->fetchOne("SELECT changes::text FROM audit_log WHERE action = 'product_category.revised'"));
    }

    public function testARevisionOfAVersionSavedSinceIsRefusedAsChangedAndWhatWasSavedStays(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->path(), ['name' => 'Matériel']);
        $id = $this->stringAt($this->json(), 'id');
        self::assertSame(1, $this->json()['version']);
        $this->sendJson('PUT', $this->path($id), ['name' => 'Outillage', 'parentId' => null, 'version' => 1]);
        self::assertSame(2, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), ['name' => 'Quincaillerie', 'parentId' => null, 'version' => 1]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('/problems/record-changed', $this->json()['type']);
        $this->getJson($this->path());
        self::assertSame([['Outillage', 2]], array_map(static fn (array $row): array => [$row['name'], $row['version']], $this->jsonList()));
    }

    public function testARevisionThatChangesNothingKeepsItsVersionAndOneWithoutAVersionIsRefused(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->path(), ['name' => 'Matériel']);
        $id = $this->stringAt($this->json(), 'id');

        $this->sendJson('PUT', $this->path($id), ['name' => 'Matériel', 'parentId' => null, 'version' => 1]);
        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->json()['version']);

        $this->sendJson('PUT', $this->path($id), ['name' => 'Outillage', 'parentId' => null]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['version'], array_column($this->arrayAt($this->json(), 'violations'), 'propertyPath'));
    }

    public function testATakenNameAnswersConflictAndACycleOrABlankNameIsRefused(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->postJson($this->path(), ['name' => 'Matériel']);
        $hardwareId = $this->stringAt($this->json(), 'id');
        $this->postJson($this->path(), ['name' => 'Portables', 'parentId' => $hardwareId]);
        $laptopsId = $this->stringAt($this->json(), 'id');

        $this->postJson($this->path(), ['name' => 'Matériel']);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);

        $this->postJson($this->path(), ['name' => '  ']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'name', 'reason' => 'value_required', 'params' => []]], $this->refusals());

        $this->sendJson('PUT', $this->path($hardwareId), ['name' => 'Matériel', 'parentId' => $laptopsId, 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('parentId', (string) $this->client->getResponse()->getContent());
        self::assertSame([['propertyPath' => 'parentId', 'reason' => 'under_itself', 'params' => []]], $this->refusals(), 'said in words under the parent the editor shows');

        $this->postJson($this->path(), ['name' => 'Gaming', 'parentId' => 'not-a-uuid']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public function testACategoryHoldingAProductOrASubcategoryIsKept(): void
    {
        $now = new \DateTimeImmutable();
        $hardware = ProductCategory::create($this->company, 'Matériel', null, $now);
        $laptops = ProductCategory::create($this->company, 'Portables', $hardware, $now);
        $unit = Unit::create($this->company, 'C62', 'Unité', 0, 0, $now);
        $this->em()->persist($hardware);
        $this->em()->persist($laptops);
        $this->em()->persist($unit);
        $this->em()->persist(Product::create($this->company, 'ART-001', new ProductDetails('Portable', null, ProductKind::Goods, '10'), $unit, $laptops, [], $now));
        $this->em()->flush();
        $this->signedIn(['product.read', 'product.write']);

        foreach ([$hardware, $laptops] as $kept) {
            $this->postJson($this->removalsPath(), ['subject' => 'product_category', 'ids' => [$kept->getId()->toRfc4122()]]);
            self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT, $kept->getName());
            self::assertSame('record_used', $this->json()['error']);
        }
        $this->getJson($this->path());
        self::assertSame([1, 0], array_column($this->jsonList(), 'childCount'));
        self::assertSame([0, 1], array_column($this->jsonList(), 'productCount'));
    }

    public function testAReaderOnlyReadsAndAnotherCompanysCategoryIsNotFound(): void
    {
        $globex = $this->createCompany('Globex');
        $theirs = ProductCategory::create($globex, 'Matériel', null, new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        // Both people exist before the first request: the client reboots the kernel, which detaches the company.
        $this->createUser('writer@twes.local', 'password-1234', $this->company, ['product.read', 'product.write'], 'writer');
        $this->signedIn(['product.read']);

        $this->getJson($this->path());
        self::assertResponseIsSuccessful();
        $this->postJson($this->path(), ['name' => 'Matériel']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->sendJson('POST', '/api/auth/logout');
        $this->login('writer@twes.local', 'password-1234');
        $this->sendJson('PUT', $this->path($theirs->getId()->toRfc4122()), ['name' => 'Mine now', 'version' => 1]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->removalsPath(), ['subject' => 'product_category', 'ids' => [$theirs->getId()->toRfc4122()]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path(), ['name' => 'Portables', 'parentId' => $theirs->getId()->toRfc4122()]);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame([['propertyPath' => 'parentId', 'reason' => 'unknown_choice', 'params' => []]], $this->refusals());
        $this->getJson('/api/companies/'.$globex->getId()->toRfc4122().'/product-categories');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('sales@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('sales@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function path(?string $categoryId = null): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/product-categories'.(null === $categoryId ? '' : '/'.$categoryId);
    }

    private function removalsPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/record-removals';
    }
}
