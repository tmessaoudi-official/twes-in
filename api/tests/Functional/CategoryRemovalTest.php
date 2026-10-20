<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * A product or expense category nothing files is deleted through the records engine, kept a day and put back as it was,
 * the defaults set for a product category with it; one a product, an expense, a subcategory or a vendor's default still
 * names is refused, naming each. The old delete that went for good is gone.
 */
final class CategoryRemovalTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testAnEmptyProductCategoryGoesWithItsDefaultsAndComesBackAsItWas(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $category = $this->aProductCategory('Visserie');
        $this->em()->getConnection()->insert('setting', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'level' => 'product_category', 'level_id' => SettingAddress::productCategory($this->company, Uuid::fromString($category))->levelId, 'key' => 'article.stock_tracking', 'value' => 'false', 'updated_at' => '2026-10-20 08:00:00']);
        $before = $this->productCategoryRows($category);

        $removal = $this->removed('product_category', $category);

        self::assertSame(['product_category' => [], 'setting' => []], $this->productCategoryRows($category));
        $this->postJson($this->path("record-removals/$removal/restore"), null);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->productCategoryRows($category));
    }

    public function testAProductCategoryAProductOrASubcategoryFilesIsRefusedAndKept(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $category = $this->aProductCategory('Outillage');
        $this->aProductCategory('Clés', $category);
        $product = $this->aProduct('CLE-10');
        $this->em()->getConnection()->update('product', ['category_id' => $category], ['id' => $product]);

        $this->postJson($this->path('record-removals'), ['subject' => 'product_category', 'ids' => [$category]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertEqualsCanonicalizing([
            ['table' => 'product', 'column' => 'category_id', 'count' => 1],
            ['table' => 'product_category', 'column' => 'parent_id', 'count' => 1],
        ], $this->usesOf($this->json()));
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product_category WHERE id = ?', [$category]));
    }

    /** A delete that went for good would leave nothing to put back: only the records engine deletes a category now. */
    public function testTheDeleteThatWentForGoodIsGone(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $category = $this->aProductCategory('Peinture');

        $this->sendJson('DELETE', $this->path("product-categories/$category"));

        self::assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM product_category WHERE id = ?', [$category]));
    }

    public function testAnExpenseCategoryNothingFilesIsDeletedAndComesBackAsItWas(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $category = $this->anExpenseCategory('Loyer');
        $before = $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM expense_category WHERE id = ?', [$category]);

        $removal = $this->removed('expense_category', $category);

        self::assertSame(0, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM expense_category WHERE id = ?', [$category]));
        $this->postJson($this->path("record-removals/$removal/restore"), null);
        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->em()->getConnection()->fetchAllAssociative('SELECT * FROM expense_category WHERE id = ?', [$category]));
    }

    public function testAnExpenseCategoryAnExpenseASubcategoryOrAVendorsDefaultNamesIsRefused(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $category = $this->anExpenseCategory('Transport');
        $this->anExpenseCategory('Carburant', $category);
        $connection = $this->em()->getConnection();
        $connection->insert('expense', [
            'id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'status' => 'draft', 'expense_date' => '2026-10-20',
            'description' => 'Taxi', 'amount_net' => '10.000', 'tax_amount' => '0.000', 'amount_gross' => '10.000', 'currency' => 'TND',
            'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00', 'fixed_amount' => '0.000',
            'amount_to_check' => 'false', 'version' => 1, 'category_id' => $category,
        ]);
        $vendor = Vendor::create($this->em()->find(Company::class, $this->company->getId()) ?? $this->company, 'F-001', new VendorProfile('Sotumag'), new \DateTimeImmutable());
        $this->em()->persist($vendor);
        $this->em()->flush();
        $connection->update('vendor', ['default_expense_category_id' => $category], ['id' => $vendor->getId()->toRfc4122()]);

        $this->postJson($this->path('record-removals'), ['subject' => 'expense_category', 'ids' => [$category]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertEqualsCanonicalizing([
            ['table' => 'expense', 'column' => 'category_id', 'count' => 1],
            ['table' => 'expense_category', 'column' => 'parent_id', 'count' => 1],
            ['table' => 'vendor', 'column' => 'default_expense_category_id', 'count' => 1],
        ], $this->usesOf($this->json()));
    }

    /** Whoever may not write expenses meets the 404 a stranger would. */
    public function testDeletingAnExpenseCategoryTakesWritingExpenses(): void
    {
        $this->signedIn(['expense.read', 'expense.write']);
        $category = $this->anExpenseCategory('Fournitures');
        $this->createUser('reader@twes.local', 'password-1234', $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'expense.read'], 'reader');
        $this->login('reader@twes.local', 'password-1234');

        $this->postJson($this->path('record-removals'), ['subject' => 'expense_category', 'ids' => [$category]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(1, $this->em()->getConnection()->fetchOne('SELECT count(*) FROM expense_category WHERE id = ?', [$category]));
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function productCategoryRows(string $category): array
    {
        $connection = $this->em()->getConnection();

        return [
            'product_category' => $connection->fetchAllAssociative('SELECT * FROM product_category WHERE id = ?', [$category]),
            'setting' => $connection->fetchAllAssociative("SELECT * FROM setting WHERE level = 'product_category' AND level_id = ? ORDER BY id", [SettingAddress::productCategory($this->company, Uuid::fromString($category))->levelId]),
        ];
    }

    /**
     * @param array<string, mixed> $refusal
     *
     * @return array<mixed>
     */
    private function usesOf(array $refusal): array
    {
        $records = $this->arrayAt($refusal, 'records');
        self::assertIsArray($records[0] ?? null);
        /** @var array<string, mixed> $record */
        $record = $records[0];

        return $this->arrayAt($record, 'uses');
    }

    private function aProductCategory(string $name, ?string $parentId = null): string
    {
        $this->postJson($this->path('product-categories'), ['name' => $name, 'parentId' => $parentId]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function anExpenseCategory(string $name, ?string $parentId = null): string
    {
        $this->postJson($this->path('expense-categories'), ['name' => $name, 'parentId' => $parentId, 'isActive' => true]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function aProduct(string $reference): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->path('products'), [
            'reference' => $reference, 'name' => $reference, 'description' => null, 'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '1.000', 'costPrice' => null, 'categoryId' => null,
            'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function removed(string $subject, string $id): string
    {
        $this->postJson($this->path('record-removals'), ['subject' => $subject, 'ids' => [$id]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('shop@twes.local', 'password-1234', $this->company, ['company.read', ...$permissions], 'member');
        $this->login('shop@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyId(): string
    {
        return $this->company->getId()->toRfc4122();
    }

    private function path(string $rest): string
    {
        return '/api/companies/'.$this->companyId().'/'.$rest;
    }
}
