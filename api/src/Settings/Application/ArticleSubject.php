<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Application;

use Symfony\Component\Uid\Uuid;

/** A product of the company, with the category whose defaults it inherits when it is filed in one. */
final readonly class ArticleSubject
{
    public function __construct(public Uuid $productId, public ?Uuid $productCategoryId)
    {
    }
}
