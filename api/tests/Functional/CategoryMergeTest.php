<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Records\Application\EndDueRemovals;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Product categories merged into one: what they filed and held goes under the category kept, their defaults with it
 * unless the one kept has its own, and they go; « Annuler la fusion » puts them back and points back what still names
 * the one kept. Merging, and undoing it, take whoever writes products and a proof given again in the last few minutes.
 */
final class CategoryMergeTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
    }

    public function testWhatTheMergedCategoriesFiledAndHeldGoesUnderTheOneKeptAndTheyGo(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $bolts = $this->aCategory('Boulons');
        $sub = $this->aCategory('Vis à bois', $screws);
        $first = $this->aProduct('VIS-4', $screws);
        $second = $this->aProduct('BOU-8', $bolts);
        $this->aSetting($screws, 'article.stock_tracking', 'false');
        $this->aSetting($kept, 'article.sales_unit', '"C62"');
        $this->aSetting($bolts, 'article.sales_unit', '"KGM"');

        $merge = $this->merged($kept, [$screws, $bolts]);

        self::assertSame('merge', $merge['kind']);
        self::assertSame(['id' => $kept, 'label' => 'Visserie'], $merge['kept']);
        self::assertEqualsCanonicalizing([['id' => $screws, 'label' => 'Vis'], ['id' => $bolts, 'label' => 'Boulons']], $merge['items']);
        self::assertSame([$kept, $kept], [$this->categoryOf($first), $this->categoryOf($second)]);
        self::assertSame($kept, $this->parentOf($sub));
        self::assertSame(0, $this->integer('SELECT count(*) FROM product_category WHERE id IN (?, ?)', [$screws, $bolts]));
        self::assertSame(['article.sales_unit' => '"C62"', 'article.stock_tracking' => 'false'], $this->settingsOf($kept));
        self::assertSame(0, $this->integer("SELECT count(*) FROM setting WHERE level = 'product_category' AND level_id IN (?, ?)", [$this->level($screws), $this->level($bolts)]));
        self::assertEqualsCanonicalizing(
            [[$screws, 'product_category.merged'], [$bolts, 'product_category.merged']],
            array_map(static fn (array $row): array => [$row['entity_id'], $row['action']], $this->em()->getConnection()->fetchAllAssociative("SELECT entity_id::text AS entity_id, action FROM audit_log WHERE action = 'product_category.merged'")),
        );
    }

    public function testUndoingTheMergePutsEveryCategoryAndEveryLinkBackAsTheyWere(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $sub = $this->aCategory('Vis à bois', $screws);
        $product = $this->aProduct('VIS-4', $screws);
        $this->aSetting($kept, 'article.sales_unit', '"C62"');
        $this->aSetting($screws, 'article.sales_unit', '"KGM"');
        $this->aSetting($screws, 'article.stock_tracking', 'false');
        $before = $this->state([$kept, $screws, $sub], $product);

        $merge = $this->merged($kept, [$screws]);
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->state([$kept, $screws, $sub], $product));
        self::assertSame(1, $this->integer("SELECT count(*) FROM audit_log WHERE action = 'product_category.merge_undone' AND entity_id = ?", [$screws]));
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('merge_undone', $this->json()['error'] ?? null);
    }

    /** What someone filed elsewhere since the merge stays where they put it. */
    public function testALinkChangedSinceTheMergeStaysAsItWasSet(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $elsewhere = $this->aCategory('Quincaillerie');
        $moved = $this->aProduct('VIS-4', $screws);
        $stayed = $this->aProduct('VIS-6', $screws);

        $merge = $this->merged($kept, [$screws]);
        $this->em()->getConnection()->update('product', ['category_id' => $elsewhere], ['id' => $moved]);
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseIsSuccessful();
        self::assertSame([$elsewhere, $screws], [$this->categoryOf($moved), $this->categoryOf($stayed)]);
    }

    /** Past its day a merge is final: what it kept for the undo goes, and the rows it moved stay with the record kept. */
    public function testAMergePastItsDayIsFinalAndLetsGoWhatItKept(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $product = $this->aProduct('VIS-4', $screws);
        $merge = $this->stringAt($this->merged($kept, [$screws]), 'id');
        $connection = $this->em()->getConnection();
        $connection->executeStatement("UPDATE record_removal SET effective_at = effective_at - interval '25 hours' WHERE id = ?", [$merge]);

        self::assertSame(1, static::getContainer()->get(EndDueRemovals::class)->handle());

        self::assertSame([0, 0], [$this->integer('SELECT count(*) FROM record_merge_move WHERE removal_id = ?', [$merge]), $this->integer('SELECT count(*) FROM record_removal_row WHERE removal_id = ?', [$merge])]);
        self::assertSame($kept, $this->categoryOf($product));
        $this->postJson($this->path('record-removals/'.$merge.'/restore'), null);
        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
    }

    /** A product open in an editor while it moved is saved over only knowingly: its version moved with it. */
    public function testARowMovedByTheMergeIsAnotherVersion(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $product = $this->aProduct('VIS-4', $screws);
        $version = $this->integer('SELECT version FROM product WHERE id = ?', [$product]);

        $this->merged($kept, [$screws]);

        self::assertSame($version + 1, $this->integer('SELECT version FROM product WHERE id = ?', [$product]));
    }

    public function testMergingAndUndoingTakeAProofGivenAgain(): void
    {
        $this->signedIn(prove: false);
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $product = $this->aProduct('VIS-4', $screws);

        $this->postJson($this->path('record-merges'), ['subject' => 'product_category', 'keptId' => $kept, 'ids' => [$screws]]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);
        self::assertSame($screws, $this->categoryOf($product));

        $this->stepUp(self::PASSWORD);
        $merge = $this->merged($kept, [$screws]);
        // A new sign-in holds no proof.
        $this->postJson('/api/auth/logout', null);
        $this->login('shop@twes.local', self::PASSWORD);
        $this->postJson($this->path('record-removals/'.$this->stringAt($merge, 'id').'/restore'), null);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        self::assertSame('step_up_required', $this->json()['error'] ?? null);
        self::assertSame($kept, $this->categoryOf($product));
    }

    public function testACategoryIsNotMergedIntoOneUnderIt(): void
    {
        $this->signedIn();
        $tools = $this->aCategory('Outillage');
        $keys = $this->aCategory('Clés', $tools);
        $flat = $this->aCategory('Clés plates', $keys);

        $this->postJson($this->path('record-merges'), ['subject' => 'product_category', 'keptId' => $flat, 'ids' => [$tools]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('merge_cycle', $this->json()['error'] ?? null);
        self::assertSame([$tools, $keys], [$this->parentOf($keys), $this->parentOf($flat)]);
    }

    public function testTheCategoryKeptIsNotAmongThoseMergedAway(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');

        $this->postJson($this->path('record-merges'), ['subject' => 'product_category', 'keptId' => $kept, 'ids' => [$screws, $kept]]);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame('merge_into_itself', $this->json()['error'] ?? null);
        self::assertSame(2, $this->integer('SELECT count(*) FROM product_category WHERE id IN (?, ?)', [$kept, $screws]));
    }

    /** Whoever may not write products, or names a category of another company, meets the 404 a stranger would. */
    public function testMergingTakesWritingProductsAndTheCompanysOwnCategories(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $foreign = $this->createCompany('Ailleurs');
        $theirs = Uuid::v7()->toRfc4122();
        $this->em()->getConnection()->insert('product_category', ['id' => $theirs, 'company_id' => $foreign->getId()->toRfc4122(), 'name' => 'Leurs vis', 'created_at' => '2026-10-20 08:00:00', 'updated_at' => '2026-10-20 08:00:00']);

        $this->postJson($this->path('record-merges'), ['subject' => 'product_category', 'keptId' => $kept, 'ids' => [$theirs]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->postJson($this->path('record-merges'), ['subject' => 'product_category', 'keptId' => $theirs, 'ids' => [$screws]]);
        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);

        $this->createUser('reader@twes.local', self::PASSWORD, $this->em()->find(Company::class, $this->company->getId()) ?? $this->company, ['company.read', 'product.read'], 'reader');
        $this->login('reader@twes.local', self::PASSWORD);
        $this->stepUp(self::PASSWORD);
        $this->postJson($this->path('record-merges'), ['subject' => 'product_category', 'keptId' => $kept, 'ids' => [$screws]]);

        self::assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        self::assertSame(2, $this->integer('SELECT count(*) FROM product_category WHERE id IN (?, ?)', [$kept, $screws]));
    }

    /** « Récemment supprimés » lists a merge beside the deletes, with the record kept. */
    public function testRecentlyDeletedListsTheMerge(): void
    {
        $this->signedIn();
        $kept = $this->aCategory('Visserie');
        $screws = $this->aCategory('Vis');
        $merge = $this->merged($kept, [$screws]);

        $this->getJson($this->path('record-removals'));

        self::assertResponseIsSuccessful();
        $listed = array_values(array_filter($this->json(), static fn (mixed $row): bool => \is_array($row) && $merge['id'] === ($row['id'] ?? null)));
        self::assertCount(1, $listed);
        self::assertSame(['merge', ['id' => $kept, 'label' => 'Visserie']], [$listed[0]['kind'] ?? null, $listed[0]['kept'] ?? null]);
    }

    /**
     * @param list<string> $categories
     *
     * @return array<string, mixed>
     */
    private function state(array $categories, string $product): array
    {
        $connection = $this->em()->getConnection();
        $levels = array_map($this->level(...), $categories);

        return [
            'categories' => $connection->fetchAllAssociative('SELECT id::text, name, parent_id::text FROM product_category WHERE id IN (?, ?, ?) ORDER BY id', $categories),
            'product' => $this->categoryOf($product),
            'settings' => $connection->fetchAllAssociative("SELECT id::text, level_id, key, value FROM setting WHERE level = 'product_category' AND level_id IN (?, ?, ?) ORDER BY id", $levels),
        ];
    }

    /**
     * @param list<string> $merged
     *
     * @return array<string, mixed>
     */
    private function merged(string $kept, array $merged): array
    {
        $this->postJson($this->path('record-merges'), ['subject' => 'product_category', 'keptId' => $kept, 'ids' => $merged]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->json();
    }

    /** @return array<string, string> value by key */
    private function settingsOf(string $category): array
    {
        $rows = $this->em()->getConnection()->fetchAllKeyValue("SELECT key, value FROM setting WHERE level = 'product_category' AND level_id = ? ORDER BY key", [$this->level($category)]);

        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : throw new \LogicException('A setting value reads as text.'), array_filter($rows, static fn (mixed $key): bool => \is_string($key), \ARRAY_FILTER_USE_KEY));
    }

    private function aSetting(string $category, string $key, string $value): void
    {
        $this->em()->getConnection()->insert('setting', ['id' => Uuid::v7()->toRfc4122(), 'company_id' => $this->companyId(), 'level' => 'product_category', 'level_id' => $this->level($category), 'key' => $key, 'value' => $value, 'updated_at' => '2026-10-20 08:00:00']);
    }

    private function level(string $category): string
    {
        return SettingAddress::productCategory($this->company, Uuid::fromString($category))->levelId;
    }

    private function categoryOf(string $product): ?string
    {
        $category = $this->em()->getConnection()->fetchOne('SELECT category_id::text FROM product WHERE id = ?', [$product]);

        return \is_string($category) ? $category : null;
    }

    private function parentOf(string $category): ?string
    {
        $parent = $this->em()->getConnection()->fetchOne('SELECT parent_id::text FROM product_category WHERE id = ?', [$category]);

        return \is_string($parent) ? $parent : null;
    }

    /** @param list<string> $parameters */
    private function integer(string $sql, array $parameters): int
    {
        $count = $this->em()->getConnection()->fetchOne($sql, $parameters);

        return \is_int($count) ? $count : throw new \LogicException('A count reads as an integer.');
    }

    private function aCategory(string $name, ?string $parentId = null): string
    {
        $this->postJson($this->path('product-categories'), ['name' => $name, 'parentId' => $parentId]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function aProduct(string $reference, string $category): string
    {
        $unit = static::getContainer()->get(UnitRepository::class)->ofCodeInCompany('C62', $this->company->getId());
        self::assertNotNull($unit);
        $this->postJson($this->path('products'), [
            'reference' => $reference, 'name' => $reference, 'description' => null, 'kind' => 'goods',
            'unitId' => $unit->getId()->toRfc4122(), 'unitPriceNet' => '1.000', 'costPrice' => null, 'categoryId' => $category,
            'defaultTaxComponentIds' => [], 'customFields' => [], 'isActive' => true,
        ]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function signedIn(bool $prove = true): void
    {
        $this->createUser('shop@twes.local', self::PASSWORD, $this->company, ['company.read', 'product.read', 'product.write'], 'member');
        $this->login('shop@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
        if ($prove) {
            $this->stepUp(self::PASSWORD);
        }
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
