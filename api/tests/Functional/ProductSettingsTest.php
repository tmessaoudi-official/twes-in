<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\Products\Domain\ProductCategory;
use App\Tenancy\Domain\Company;
use Symfony\Component\HttpFoundation\Response;

/**
 * The articles chain at the product-category and product levels, through the one settings endpoint: a category's
 * value reaches its products, a product's own wins, and both belong to whoever may change products.
 */
final class ProductSettingsTest extends ApiTestCase
{
    private const string UNIT = 'article.default_unit';
    private const string ABSENT = '0192c3a4-0000-7000-8000-000000000000';
    private const array WRITER = ['company.read', 'product.read', 'product.write'];

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Acme');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testACategorysUnitReachesItsProductUntilTheProductSaysOtherwise(): void
    {
        $this->signedIn('writer@twes.local', self::WRITER);
        [$categoryId, $productId] = $this->categoryWithAProduct();

        $this->sendJson('PUT', $this->path(self::UNIT), ['level' => 'product_category', 'productCategoryId' => $categoryId, 'value' => 'HUR']);
        self::assertResponseIsSuccessful();
        self::assertSame('product_category', $this->json()['source']);

        $this->getJson($this->path().'?chain=articles&productId='.$productId);
        self::assertResponseIsSuccessful();
        $unit = $this->row(self::UNIT);
        self::assertSame(['HUR', 'product_category'], [$unit['value'], $unit['source']]);
        self::assertSame(['product'], $unit['writableLevels']);

        $this->getJson($this->path().'?chain=articles&productCategoryId='.$categoryId);
        self::assertSame(['product_category'], $this->row(self::UNIT)['writableLevels']);

        $this->sendJson('PUT', $this->path(self::UNIT), ['level' => 'product', 'productId' => $productId, 'value' => 'KGM']);
        self::assertResponseIsSuccessful();
        self::assertSame(['KGM', 'product'], [$this->json()['value'], $this->json()['source']]);

        $this->sendJson('DELETE', $this->path(self::UNIT).'?level=product&productId='.$productId);
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        $this->getJson($this->path().'?chain=articles&productId='.$productId);
        self::assertSame('HUR', $this->row(self::UNIT)['value']);

        $this->getJson($this->path().'?chain=articles');
        self::assertSame('C62', $this->row(self::UNIT)['value'], 'the company itself still says C62');
    }

    public function testAReaderOfProductsReadsTheirSettingsButChangesNone(): void
    {
        // Every person exists before the first request: the client reboots the kernel, which detaches the company.
        $this->createUser('reader@twes.local', 'password-1234', $this->company, ['company.read', 'product.read'], 'reader');
        $this->signedIn('writer@twes.local', self::WRITER);
        [$categoryId, $productId] = $this->categoryWithAProduct();
        $this->sendJson('POST', '/api/auth/logout');
        $this->login('reader@twes.local', 'password-1234');

        $this->getJson($this->path().'?chain=articles&productId='.$productId);
        self::assertResponseIsSuccessful();
        self::assertSame([], $this->row(self::UNIT)['writableLevels']);

        $this->sendJson('PUT', $this->path(self::UNIT), ['level' => 'product', 'productId' => $productId, 'value' => 'KGM']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('DELETE', $this->path(self::UNIT).'?level=product_category&productCategoryId='.$categoryId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testWithoutProductReadAProductsOrACategorysSettingsAreNotFound(): void
    {
        $this->createUser('clerk@twes.local', 'password-1234', $this->company, ['company.read'], 'clerk');
        $this->signedIn('writer@twes.local', self::WRITER);
        [$categoryId, $productId] = $this->categoryWithAProduct();
        $this->sendJson('POST', '/api/auth/logout');
        $this->login('clerk@twes.local', 'password-1234');

        $this->getJson($this->path().'?chain=articles&productId='.$productId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path().'?chain=articles&productCategoryId='.$categoryId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testAnotherCompanysCategoryOrAnUnknownProductIsNotFound(): void
    {
        $theirs = ProductCategory::create($this->createCompany('Globex'), 'Matériel', null, new \DateTimeImmutable());
        $this->em()->persist($theirs);
        $this->em()->flush();
        $theirId = $theirs->getId()->toRfc4122();
        $this->signedIn('writer@twes.local', self::WRITER);

        $this->getJson($this->path().'?chain=articles&productCategoryId='.$theirId);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->sendJson('PUT', $this->path(self::UNIT), ['level' => 'product_category', 'productCategoryId' => $theirId, 'value' => 'HUR']);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path().'?chain=articles&productId='.self::ABSENT);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->getJson($this->path().'?chain=articles&productId=not-a-uuid');
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testOneSubjectIsNamedWhateverItsChainAndItMatchesTheLevel(): void
    {
        $this->signedIn('writer@twes.local', ['company.read', 'customer.read', 'product.read', 'product.write']);

        $this->getJson($this->path().'?chain=articles&productId='.self::ABSENT.'&productCategoryId='.self::ABSENT);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $this->getJson($this->path().'?customerId='.self::ABSENT.'&productId='.self::ABSENT);
        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);

        $this->sendJson('PUT', $this->path(self::UNIT), ['level' => 'product', 'value' => 'KGM']);
        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString('productId', (string) $this->client->getResponse()->getContent());
    }

    public function testDeletingACategoryForgetsItsSettings(): void
    {
        $this->signedIn('writer@twes.local', self::WRITER);
        $this->postJson($this->companyPath().'/product-categories', ['name' => 'Services']);
        $categoryId = $this->stringAt($this->json(), 'id');
        $this->sendJson('PUT', $this->path(self::UNIT), ['level' => 'product_category', 'productCategoryId' => $categoryId, 'value' => 'HUR']);
        self::assertResponseIsSuccessful();

        $this->sendJson('DELETE', $this->companyPath().'/product-categories/'.$categoryId);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
        self::assertEquals(0, $this->em()->getConnection()->fetchOne("SELECT count(*) FROM setting WHERE level = 'product_category'"));
    }

    /** @return array{string, string} a category of the company and a product filed in it, both made through the API */
    private function categoryWithAProduct(): array
    {
        $this->postJson($this->companyPath().'/product-categories', ['name' => 'Matériel']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $categoryId = $this->stringAt($this->json(), 'id');
        $this->getJson($this->companyPath().'/product-options');
        $units = array_column($this->arrayAt($this->json(), 'units'), 'id', 'code');
        $this->postJson($this->companyPath().'/products', [
            'reference' => 'ART-001',
            'name' => 'Portable 14"',
            'kind' => 'goods',
            'unitId' => $units['C62'],
            'unitPriceNet' => '1250',
            'categoryId' => $categoryId,
            'defaultTaxComponentIds' => [],
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return [$categoryId, $this->stringAt($this->json(), 'id')];
    }

    /** @param list<string> $permissions */
    private function signedIn(string $email, array $permissions): void
    {
        $this->createUser($email, 'password-1234', $this->company, $permissions, 'member');
        $this->login($email, 'password-1234');
        self::assertResponseIsSuccessful();
    }

    /** @return array<string, mixed> */
    private function row(string $key): array
    {
        foreach ($this->jsonList() as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail("No setting $key in the response.");
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function path(?string $key = null): string
    {
        return $this->companyPath().'/settings'.(null === $key ? '' : '/'.$key);
    }
}
