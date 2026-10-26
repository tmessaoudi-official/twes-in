<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Tenancy\Domain\Model\Company;

/**
 * The product categories a shop already files its goods under, read from a file of their own before its products are:
 * a name, found again by it, and the category it sits under, named on an earlier row or already held.
 */
final class ProductCategoryImportTest extends ApiTestCase
{
    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->em()->persist(ProductCategory::create($this->company, 'Outillage', null, new \DateTimeImmutable()));
        $this->em()->flush();
    }

    public function testAFileCreatesCategoriesUnderOnesHeldOrNamedAbove(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->import("name,parent\nQuincaillerie,\nVisserie,Quincaillerie\nPerceuses,Outillage\n");

        self::assertResponseIsSuccessful();
        self::assertSame(['committed' => true, 'created' => [2, 3, 4], 'updated' => [], 'unchanged' => [], 'rejected' => [], 'changes' => [], 'notes' => [], 'alreadyImportedAt' => null, 'encoding' => 'utf-8'], $this->importReport());
        self::assertSame(['Outillage' => null, 'Perceuses' => 'Outillage', 'Quincaillerie' => null, 'Visserie' => 'Quincaillerie'], $this->tree());
    }

    public function testEveryRejectedRowCarriesACodeAndItsParameters(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->import(
            "name,parent\n"
            .",Outillage\n"
            ."Visserie,Quincaillerie\n"
            ."Quincaillerie,\n"
            ."Outillage,\n"
            ."Boucle,Boucle\n"
            .str_repeat('x', ProductCategory::NAME_MAX + 1).",\n"
            ."Quincaillerie,\n",
            dryRun: true,
        );

        self::assertResponseIsSuccessful();
        $rejected = array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null, $row['params'] ?? null] : [],
            $this->arrayAt($this->json(), 'rejected'),
        );
        self::assertSame([
            [2, 'name', 'value_required', []],
            // A parent named on a LATER row is not there yet: the file lists a category before what sits under it.
            [3, 'parent', 'unknown_category', ['name' => 'Quincaillerie']],
            [5, 'name', 'already_exists', []],
            [6, 'parent', 'unknown_category', ['name' => 'Boucle']],
            [7, 'name', 'invalid_text', ['max' => ProductCategory::NAME_MAX]],
            [8, 'name', 'duplicate_in_file', ['line' => 4]],
        ], $rejected);
    }

    public function testAnUpsertMovesACategoryUnderAnotherAndSaysWhatChanges(): void
    {
        $this->signedIn(['product.read', 'product.write']);
        $this->import("name,parent\nQuincaillerie,\nVisserie,\n");
        self::assertResponseIsSuccessful();

        $this->import("name,parent\nVisserie,Quincaillerie\nQuincaillerie,\nOutillage,\n", 'upsert');

        self::assertResponseIsSuccessful();
        $report = $this->importReport();
        self::assertSame([[2], [3, 4]], [$report['updated'], $report['unchanged']]);
        self::assertSame('Quincaillerie', $this->tree()['Visserie']);

        // A blank parent keeps the one held; a category never sits under one of its own.
        $this->import("name,parent\nVisserie,\nQuincaillerie,Visserie\n", 'upsert', dryRun: true);
        self::assertSame([[3, 'parent', 'under_itself']], array_map(
            static fn (mixed $row): array => \is_array($row) ? [$row['line'] ?? null, $row['column'] ?? null, $row['code'] ?? null] : [],
            $this->arrayAt($this->json(), 'rejected'),
        ));
    }

    public function testAnImportIsUndoneWholeWithTheCategoriesUnderItsOwn(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write']);
        $this->import("name,parent\nQuincaillerie,\nVisserie,Quincaillerie\nPerceuses,Outillage\n");
        self::assertResponseIsSuccessful();
        $run = $this->stringAt($this->json(), 'run');

        $this->stepUp('password-1234');
        $this->postJson($this->companyPath().'/import-runs/'.$run.'/undo', null);

        self::assertResponseIsSuccessful();
        self::assertSame(['Outillage' => null], $this->tree(), 'what the file created goes, a category under another of its own included');
    }

    public function testAnUndoKeepsACategoryWhatItKeepsStillSitsUnder(): void
    {
        $this->signedIn(['company.read', 'product.read', 'product.write']);
        $this->import("name,parent\nQuincaillerie,\nVisserie,Quincaillerie\nPerceuses,Outillage\n");
        self::assertResponseIsSuccessful();
        $run = $this->stringAt($this->json(), 'run');
        $this->postJson($this->companyPath().'/product-categories', ['name' => 'Vis à bois', 'parentId' => $this->idOf('Visserie')]);
        self::assertResponseStatusCodeSame(201);

        $this->stepUp('password-1234');
        $this->postJson($this->companyPath().'/import-runs/'.$run.'/undo', null);

        // Visserie holds a category made by hand, so it stays, and the category it sits under with it.
        self::assertResponseIsSuccessful();
        $answer = $this->json();
        self::assertSame(['Perceuses'], array_column($this->arrayAt($answer, 'removed'), 'label'));
        $kept = array_column($this->arrayAt($answer, 'kept'), 'label');
        sort($kept);
        self::assertSame(['Quincaillerie', 'Visserie'], $kept);
        self::assertSame(['Outillage' => null, 'Quincaillerie' => null, 'Vis à bois' => 'Visserie', 'Visserie' => 'Quincaillerie'], $this->tree());
    }

    public function testOnlyWhoeverWritesProductsImportsTheirCategories(): void
    {
        $this->signedIn(['product.read']);

        $this->import("name,parent\nQuincaillerie,\n");

        // A right the role lacks answers as a stranger is answered, so nothing says which.
        self::assertResponseStatusCodeSame(404);
        self::assertSame(['Outillage' => null], $this->tree());
    }

    public function testTheGuideDescribesTheColumns(): void
    {
        $this->signedIn(['product.read', 'product.write']);

        $this->getJson($this->companyPath().'/imports/product-categories');

        self::assertResponseIsSuccessful();
        self::assertSame(['name', 'parent'], array_column($this->arrayAt($this->json(), 'columns'), 'key'));
        self::assertSame(['name'], $this->json()['identity'] ?? null);
    }

    private function import(string $contents, string $mode = 'create', bool $dryRun = false): void
    {
        $this->uploadFile($this->companyPath().'/imports/product-categories', 'categories.csv', $contents, 'file', ['mode' => $mode, 'dryRun' => $dryRun ? '1' : '0']);
    }

    /** @param list<string> $permissions */
    private function signedIn(array $permissions): void
    {
        $this->createUser('buyer@twes.local', 'password-1234', $this->company, $permissions, 'member');
        $this->login('buyer@twes.local', 'password-1234');
        self::assertResponseIsSuccessful();
    }

    private function companyPath(): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122();
    }

    private function idOf(string $name): string
    {
        $id = $this->em()->getConnection()->fetchOne('SELECT id FROM product_category WHERE company_id = ? AND name = ?', [$this->company->getId()->toRfc4122(), $name]);
        self::assertIsString($id);

        return $id;
    }

    /** @return array<string, ?string> each category's parent by its name, sorted by name */
    private function tree(): array
    {
        $this->em()->clear();
        $tree = [];
        foreach ($this->em()->getRepository(ProductCategory::class)->findBy(['company' => $this->company->getId()]) as $category) {
            $tree[$category->getName()] = $category->getParent()?->getName();
        }
        ksort($tree);

        return $tree;
    }
}
