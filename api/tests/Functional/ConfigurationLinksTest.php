<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Records\Application\EndDueRemovals;
use App\Settings\Domain\Model\Setting;
use App\Settings\Domain\ValueObject\SettingAddress;
use App\Shared\Infrastructure\Realtime\LiveChangesAnswer;
use App\Tenancy\Domain\Model\Company;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\ConstraintViolationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A configuration link (a subcategory's category, a vendor's default expense category) refuses the delete by default,
 * as any use does; a company that chose « clear » (records.configuration_links) has the delete empty it instead, says
 * how many it emptied, and the restore writes each back where it is still empty and makes no loop, saying what it left.
 */
final class ConfigurationLinksTest extends ApiTestCase
{
    private const string PASSWORD = 'password-1234';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = $this->createCompany('Quincaillerie');
        static::getContainer()->get(ProvisionCompany::class)->handle($this->company);
        $this->createUser('shop@twes.local', self::PASSWORD, $this->company, ['company.read', 'product.read', 'product.write', 'expense.read', 'expense.write'], 'member');
        $this->login('shop@twes.local', self::PASSWORD);
        self::assertResponseIsSuccessful();
    }

    public function testByDefaultASubcategoryStillRefusesItsCategorysDelete(): void
    {
        $tools = $this->aProductCategory('Outillage');
        $this->aProductCategory('Clés', $tools);

        $this->postJson($this->path('record-removals'), ['subject' => 'product_category', 'ids' => [$tools]]);

        self::assertResponseStatusCodeSame(Response::HTTP_CONFLICT);
        self::assertSame('record_used', $this->json()['error'] ?? null);
    }

    /** The list says which category a delete is offered on, as the company chose: the screen reads it, never guesses. */
    public function testTheCategoriesListOffersTheDeleteAsTheCompanyChose(): void
    {
        $tools = $this->aProductCategory('Outillage');
        $keys = $this->aProductCategory('Clés', $tools);

        self::assertSame(self::sorted([$tools => false, $keys => true]), $this->offered());

        $this->clearing();

        self::assertSame(self::sorted([$tools => true, $keys => true]), $this->offered());
    }

    public function testClearedTheDeleteEmptiesEachLinkAndSaysHowManyThenTheRestoreWritesThemBack(): void
    {
        $this->clearing();
        $transport = $this->anExpenseCategory('Transport');
        $fuel = $this->anExpenseCategory('Carburant', $transport);
        $vendor = $this->aVendor('F-001', $transport);

        $removal = $this->removed('expense_category', $transport);

        self::assertEqualsCanonicalizing([
            ['kind' => 'records.links_cleared', 'id' => $removal, 'params' => ['table' => 'expense_category', 'column' => 'parent_id', 'count' => 1]],
            ['kind' => 'records.links_cleared', 'id' => $removal, 'params' => ['table' => 'vendor', 'column' => 'default_expense_category_id', 'count' => 1]],
        ], $this->effects('records.links_cleared'));
        self::assertNull($this->value('expense_category', 'parent_id', $fuel));
        self::assertNull($this->value('vendor', 'default_expense_category_id', $vendor));
        // The vendors' screens hear it, as the categories' do.
        self::assertContains('vendor', $this->kindsChanged());
        // Emptied, each is another version, so an editor that had it open saves over it only knowingly.
        self::assertSame([2, 2], [$this->version('expense_category', $fuel), $this->version('vendor', $vendor)]);

        $this->postJson($this->path("record-removals/$removal/restore"), null, server: ['HTTP_X_TAB' => 'tab-1']);

        self::assertResponseIsSuccessful();
        self::assertContains('vendor', $this->kindsChanged());
        self::assertSame([], $this->effects('records.links_not_restored'));
        self::assertSame([$transport, $transport], [$this->value('expense_category', 'parent_id', $fuel), $this->value('vendor', 'default_expense_category_id', $vendor)]);
        self::assertSame([3, 3], [$this->version('expense_category', $fuel), $this->version('vendor', $vendor)]);
        self::assertSame(0, $this->integer('SELECT count(*) FROM record_removal_row WHERE removal_id = ?', [$removal]));
    }

    /** A subcategory chosen beside its category goes with it, its own link kept in its copy, and both come back as they were. */
    public function testASubcategoryDeletedBesideItsCategoryComesBackUnderIt(): void
    {
        $this->clearing();
        $tools = $this->aProductCategory('Outillage');
        $keys = $this->aProductCategory('Clés', $tools);
        $flat = $this->aProductCategory('Clés plates', $keys);
        $before = $this->rows([$tools, $keys], []);

        $this->postJson($this->path('record-removals'), ['subject' => 'product_category', 'ids' => [$tools, $keys]]);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);
        $removal = $this->stringAt($this->json(), 'id');
        // Only the subcategory left behind lost its link.
        self::assertSame([['kind' => 'records.links_cleared', 'id' => $removal, 'params' => ['table' => 'product_category', 'column' => 'parent_id', 'count' => 1]]], $this->effects('records.links_cleared'));
        self::assertNull($this->value('product_category', 'parent_id', $flat));

        $this->postJson($this->path("record-removals/$removal/restore"), null);

        self::assertResponseIsSuccessful();
        self::assertSame($before, $this->rows([$tools, $keys], []));
        self::assertSame($keys, $this->value('product_category', 'parent_id', $flat));
    }

    /** What someone filed elsewhere since stays where they put it; a link that would make a loop stays empty; both are said. */
    public function testTheRestoreLeavesALinkSetSinceOrOneMakingALoopAndSaysIt(): void
    {
        $this->clearing();
        $top = $this->aProductCategory('Quincaillerie');
        $tools = $this->aProductCategory('Outillage', $top);
        $keys = $this->aProductCategory('Clés', $tools);
        $saws = $this->aProductCategory('Scies', $tools);
        $elsewhere = $this->aProductCategory('Jardin');

        $removal = $this->removed('product_category', $tools);
        $connection = $this->em()->getConnection();
        // Filed elsewhere since: stays there.
        $connection->update('product_category', ['parent_id' => $elsewhere], ['id' => $saws]);
        // The category above the one deleted moved under what it held: writing the link back would make a loop.
        $connection->update('product_category', ['parent_id' => $keys], ['id' => $top]);

        $this->postJson($this->path("record-removals/$removal/restore"), null);

        self::assertResponseIsSuccessful();
        self::assertSame([['kind' => 'records.links_not_restored', 'id' => $removal, 'params' => ['table' => 'product_category', 'column' => 'parent_id', 'count' => 2]]], $this->effects('records.links_not_restored'));
        self::assertSame($top, $this->value('product_category', 'parent_id', $tools));
        self::assertSame($elsewhere, $this->value('product_category', 'parent_id', $saws));
        self::assertNull($this->value('product_category', 'parent_id', $keys));
    }

    /** A record whose link was emptied and that was deleted since is not put back by a link: the rest of the restore is. */
    public function testALinkedRecordDeletedSinceIsLeftAndTheRestStillComesBack(): void
    {
        $this->clearing();
        $transport = $this->anExpenseCategory('Transport');
        $fuel = $this->anExpenseCategory('Carburant', $transport);

        $removal = $this->removed('expense_category', $transport);
        $this->removed('expense_category', $fuel);
        $this->postJson($this->path("record-removals/$removal/restore"), null);

        self::assertResponseIsSuccessful();
        self::assertSame(1, $this->integer('SELECT count(*) FROM expense_category WHERE id = ?', [$transport]));
        self::assertSame([['kind' => 'records.links_not_restored', 'id' => $removal, 'params' => ['table' => 'expense_category', 'column' => 'parent_id', 'count' => 1]]], $this->effects('records.links_not_restored'));
    }

    /**
     * The release before this one restores by inserting every copied row whole: an emptied link's copy holds only its row
     * and the value, so that insert fails, in the other table as in the subject's own, and its whole restore is refused
     * rather than half done.
     */
    public function testThePreviousReleasesRestoreRefusesAnEmptiedLinksCopyWhole(): void
    {
        $this->clearing();
        $transport = $this->anExpenseCategory('Transport');
        $this->anExpenseCategory('Carburant', $transport);
        $this->aVendor('F-001', $transport);
        $removal = $this->removed('expense_category', $transport);
        $connection = $this->em()->getConnection();
        $groups = $connection->fetchAllAssociative('SELECT step, table_name FROM record_removal_row WHERE removal_id = ? AND cleared_column IS NOT NULL GROUP BY step, table_name ORDER BY step, table_name', [$removal]);
        self::assertSame([['step' => 3, 'table_name' => 'expense_category'], ['step' => 3, 'table_name' => 'vendor']], $groups);

        foreach ($groups as $group) {
            $connection->beginTransaction();
            try {
                $columns = $connection->fetchFirstColumn(
                    "SELECT c.column_name FROM information_schema.columns c WHERE c.table_schema = current_schema() AND c.table_name = :table AND c.is_generated = 'NEVER' AND EXISTS (SELECT 1 FROM record_removal_row d CROSS JOIN LATERAL jsonb_object_keys(d.snapshot) AS k(name) WHERE d.removal_id = :removal AND d.table_name = :table AND d.step = :step AND k.name = c.column_name) ORDER BY c.ordinal_position",
                    ['table' => $group['table_name'], 'removal' => $removal, 'step' => $group['step']],
                );
                self::assertSame(['id'], $columns);
                $refused = false;
                try {
                    $connection->executeStatement(
                        \sprintf('INSERT INTO %1$s (id) SELECT r.id FROM record_removal_row d CROSS JOIN LATERAL jsonb_populate_record(NULL::%1$s, d.snapshot) AS r WHERE d.removal_id = :removal AND d.table_name = :table AND d.step = :step', $group['table_name']),
                        ['table' => $group['table_name'], 'removal' => $removal, 'step' => $group['step']],
                    );
                } catch (ConstraintViolationException) {
                    $refused = true;
                }
                self::assertTrue($refused, $group['table_name'].': the previous release would insert an emptied link\'s copy.');
            } finally {
                $connection->rollBack();
            }
        }
    }

    /** Past its day the delete is final: what it kept for the restore goes, and the links it emptied stay empty. */
    public function testPastItsDayTheDeleteIsFinalAndTheLinksStayEmpty(): void
    {
        $this->clearing();
        $transport = $this->anExpenseCategory('Transport');
        $vendor = $this->aVendor('F-001', $transport);
        $removal = $this->removed('expense_category', $transport);

        $this->moveClock('+25 hours');
        static::getContainer()->get(EndDueRemovals::class)->handle();

        self::assertSame(0, $this->integer('SELECT count(*) FROM record_removal_row WHERE removal_id = ?', [$removal]));
        self::assertNull($this->value('vendor', 'default_expense_category_id', $vendor));
    }

    /** @return list<string> the kinds the last answer names as changed, for the tab that asked */
    private function kindsChanged(): array
    {
        $named = json_decode($this->client->getResponse()->headers->get(LiveChangesAnswer::HEADER) ?? '[]', true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($named);
        $kinds = [];
        foreach ($named as $change) {
            self::assertIsArray($change);
            self::assertIsString($change['kind'] ?? null);
            $kinds[] = $change['kind'];
        }

        return $kinds;
    }

    /** @return array<string, mixed> each category's id and whether its delete is offered */
    private function offered(): array
    {
        $this->getJson($this->path('product-categories'));
        self::assertResponseIsSuccessful();
        $offered = [];
        foreach ($this->json() as $row) {
            self::assertIsArray($row);
            self::assertIsString($row['id'] ?? null);
            $offered[$row['id']] = $row['offersDelete'] ?? null;
        }
        ksort($offered);

        return $offered;
    }

    /**
     * @param array<string, bool> $offered
     *
     * @return array<string, bool>
     */
    private static function sorted(array $offered): array
    {
        ksort($offered);

        return $offered;
    }

    private function clearing(): void
    {
        $company = $this->em()->find(Company::class, $this->company->getId()) ?? $this->company;
        $this->em()->persist(new Setting(SettingAddress::company($company), 'records.configuration_links', 'clear', self::heldNow()));
        $this->em()->flush();
    }

    /**
     * @param list<string> $categories product or expense categories, read whole
     * @param list<string> $vendors
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function rows(array $categories, array $vendors): array
    {
        $connection = $this->em()->getConnection();
        $rows = [];
        foreach (['product_category', 'expense_category'] as $table) {
            $rows[$table] = [] === $categories ? [] : $connection->fetchAllAssociative(\sprintf('SELECT * FROM %s WHERE id::text IN (?) ORDER BY id', $table), [$categories], [ArrayParameterType::STRING]);
        }
        $rows['vendor'] = [] === $vendors ? [] : $connection->fetchAllAssociative('SELECT * FROM vendor WHERE id::text IN (?) ORDER BY id', [$vendors], [ArrayParameterType::STRING]);

        return $rows;
    }

    private function value(string $table, string $column, string $id): ?string
    {
        $value = $this->em()->getConnection()->fetchOne(\sprintf('SELECT %s::text FROM %s WHERE id = ?', $column, $table), [$id]);

        return \is_string($value) ? $value : null;
    }

    private function version(string $table, string $id): int
    {
        return $this->integer(\sprintf('SELECT version FROM %s WHERE id = ?', $table), [$id]);
    }

    /** @param list<mixed> $parameters */
    private function integer(string $sql, array $parameters): int
    {
        $value = $this->em()->getConnection()->fetchOne($sql, $parameters);
        self::assertIsInt($value);

        return $value;
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

    private function aVendor(string $number, string $defaultCategory): string
    {
        $vendor = Vendor::create($this->em()->find(Company::class, $this->company->getId()) ?? $this->company, $number, new VendorProfile('Sotumag'), self::heldNow());
        $this->em()->persist($vendor);
        $this->em()->flush();
        $this->em()->getConnection()->update('vendor', ['default_expense_category_id' => $defaultCategory], ['id' => $vendor->getId()->toRfc4122()]);

        return $vendor->getId()->toRfc4122();
    }

    private function removed(string $subject, string $id): string
    {
        $this->postJson($this->path('record-removals'), ['subject' => $subject, 'ids' => [$id]], server: ['HTTP_X_TAB' => 'tab-1']);
        self::assertResponseStatusCodeSame(Response::HTTP_CREATED);

        return $this->stringAt($this->json(), 'id');
    }

    private function path(string $rest): string
    {
        return '/api/companies/'.$this->company->getId()->toRfc4122().'/'.$rest;
    }
}
