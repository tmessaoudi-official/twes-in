<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Inventory;

use App\Module\Inventory\Application\CountedProducts;
use App\Module\Products\Application\NoteProductCounted;
use App\Module\Products\Domain\Model\Product;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** Answers the inventory's `CountedProducts` port: a count clears « à compter » as this module clears it. */
final readonly class ProductCountedOnCount implements CountedProducts
{
    public function __construct(private NoteProductCounted $note)
    {
    }

    public function counted(Company $company, Product $product, ?Uuid $actorUserId): void
    {
        $this->note->handle($company, $product, $actorUserId);
    }
}
