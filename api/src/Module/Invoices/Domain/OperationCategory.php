<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Module\Products\Domain\Product;
use App\Module\Products\Domain\ProductKind;

/**
 * What the operations an invoice bills are: deliveries of goods only, services only, or both (CGI ann. II art. 242
 * nonies A I 8° bis, docs/fiscal/FR.md § 4a).
 */
enum OperationCategory: string
{
    case Goods = 'goods';
    case Services = 'services';
    case Both = 'both';

    /**
     * What the products sold say: goods, services or both. A line naming no product has no kind, so nothing can be said
     * of operations that include one: null, as for no product at all.
     *
     * @param iterable<Product|null> $products one a line, null for a line naming none
     */
    public static function ofProducts(iterable $products): ?self
    {
        $kinds = [];
        foreach ($products as $product) {
            if (null === $product) {
                return null;
            }
            $kinds[$product->getDetails()->kind->value] = true;
        }
        if ([] === $kinds) {
            return null;
        }
        if (isset($kinds[ProductKind::Goods->value], $kinds[ProductKind::Service->value])) {
            return self::Both;
        }

        return isset($kinds[ProductKind::Goods->value]) ? self::Goods : self::Services;
    }

    /** Whether services are among the operations, which is what the débits option concerns. */
    public function includesServices(): bool
    {
        return self::Goods !== $this;
    }
}
