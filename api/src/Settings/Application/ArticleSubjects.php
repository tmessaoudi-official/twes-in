<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The articles chain's subjects, as the module that owns them knows them. The settings engine asks whether a product
 * category is the company's, and which category a product is filed in; the products module answers, so the engine
 * never depends on it.
 */
interface ArticleSubjects
{
    public function hasProductCategory(Company $company, Uuid $categoryId): bool;

    /** @return ArticleSubject|null null when the company has no such product */
    public function product(Company $company, Uuid $productId): ?ArticleSubject;
}
