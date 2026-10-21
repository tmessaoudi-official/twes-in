<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Import;

use App\ImportExport\Application\DeclaresImport;
use App\ImportExport\Application\ImportColumn;
use App\ImportExport\Application\ImportContext;
use App\ImportExport\Application\ImportMode;
use App\ImportExport\Application\ImportRecord;
use App\ImportExport\Application\ImportSubject;
use App\ImportExport\Application\RowIdentity;
use App\ImportExport\Application\RowImported;
use App\ImportExport\Application\RowNotes;
use App\ImportExport\Application\RowRejected;
use App\Module\Products\Application\Category\ManageProductCategories;
use App\Module\Products\Application\Category\ProductCategoryNameTaken;
use App\Module\Products\Domain\Exception\InvalidProductCategory;
use App\Module\Products\Domain\Model\ProductCategory;
use App\Module\Products\Domain\Repository\ProductCategoryRepository;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The categories a shop files its goods under, read from a file of their own before its products name them. A row is
 * a category found again by its name, and the category it sits under: one already held or named on an earlier row,
 * since a file lists a category before what sits under it. Written through the use case the categories page uses, so
 * a file is held to its rules: a name used once, never under itself.
 */
final readonly class ProductCategoryImport implements DeclaresImport
{
    public const string KEY = 'product-categories';

    public function __construct(
        private ManageProductCategories $manage,
        private ProductCategoryRepository $categories,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::WRITE;
    }

    public function module(): string
    {
        return ProductsModule::KEY;
    }

    public function identityColumns(): array
    {
        return ['name'];
    }

    public function identityOf(Company $company, ImportRecord $record): ?RowIdentity
    {
        return RowIdentity::ofColumns($record, $this->identityColumns());
    }

    public function finished(Company $company, ImportContext $context, ?Uuid $actorUserId): void
    {
    }

    public function subjectFor(Company $company): ImportSubject
    {
        return new ImportSubject(self::KEY, [
            new ImportColumn('name', 'import.product_categories.name', true, 'Visserie', 'import.product_categories.name_note'),
            new ImportColumn('parent', 'import.product_categories.parent', false, 'Quincaillerie', 'import.product_categories.parent_note'),
        ]);
    }

    public function import(Company $company, ImportRecord $record, ImportMode $mode, ?Uuid $actorUserId, RowNotes $notes, ImportContext $context): RowImported
    {
        $name = $record->value('name') ?? throw new RowRejected('name', 'A category is found again by its name, so every row needs one.', 'value_required');
        $existing = $this->categories->ofNameInCompany($name, $company->getId());
        if (null !== $existing && ImportMode::Create === $mode) {
            throw new RowRejected('name', 'A category already has this name. Import in "create and update" mode to update it.', 'already_exists');
        }
        // A blank parent keeps the one a category holds; a new one then sits at the top.
        $parentId = $this->parentId($company, $record) ?? $existing?->getParent()?->getId();

        try {
            if (null === $existing) {
                $created = $this->manage->create($company, $name, $parentId, $actorUserId);
                $context->created(ManageProductCategories::ENTITY_TYPE, $created->getId());

                return RowImported::Created;
            }
            $before = self::inFile($existing, $context);
            $revised = $this->manage->revise($company, $existing->getId(), $existing->getName(), $parentId, $actorUserId);

            return $notes->changedBetween($before, self::inFile($revised, $context)) ? RowImported::Updated : RowImported::Unchanged;
        } catch (InvalidProductCategory $refused) {
            throw new RowRejected('parentId' === $refused->field ? 'parent' : 'name', $refused->getMessage(), 'invalid_value');
        } catch (ProductCategoryNameTaken) {
            throw new RowRejected('name', 'A category already has this name.', 'already_exists');
        }
    }

    /** @throws RowRejected when the row names a parent the company does not hold yet */
    private function parentId(Company $company, ImportRecord $record): ?Uuid
    {
        $name = $record->value('parent');
        if (null === $name) {
            return null;
        }

        return $this->categories->ofNameInCompany($name, $company->getId())?->getId()
            ?? throw new RowRejected('parent', \sprintf('The company has no product category named "%s". Name it on an earlier row, or create it first.', $name), 'unknown_category', ['name' => $name]);
    }

    /**
     * The category as the file writes it, over the columns of this run.
     *
     * @return array<string, string> by column
     */
    private static function inFile(ProductCategory $category, ImportContext $context): array
    {
        $cells = [];
        foreach ($context->columns as $column) {
            $cells[$column] = match ($column) {
                'name' => $category->getName(),
                'parent' => $category->getParent()?->getName() ?? '',
                default => '',
            };
        }

        return $cells;
    }
}
