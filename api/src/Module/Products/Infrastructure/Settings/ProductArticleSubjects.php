<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Settings;

use App\Module\Products\Domain\ProductCategoryRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Settings\Application\ArticleSubject;
use App\Settings\Application\ArticleSubjects;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The products module answering the settings engine about its categories and products. Switched off, the module has
 * none to offer: their settings answer 404 like its own resources, and are kept.
 */
final readonly class ProductArticleSubjects implements ArticleSubjects
{
    public function __construct(private ProductCategoryRepository $categories, private ProductRepository $products, private ModuleStates $modules)
    {
    }

    public function hasProductCategory(Company $company, Uuid $categoryId): bool
    {
        return $this->modules->isEnabled($company->getId(), ProductsModule::KEY) && null !== $this->categories->ofIdInCompany($categoryId, $company->getId());
    }

    public function product(Company $company, Uuid $productId): ?ArticleSubject
    {
        if (!$this->modules->isEnabled($company->getId(), ProductsModule::KEY)) {
            return null;
        }
        $product = $this->products->ofIdInCompany($productId, $company->getId());

        return null === $product ? null : new ArticleSubject($product->getId(), $product->getCategory()?->getId());
    }
}
