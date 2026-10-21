<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\ValueObject;

/**
 * Which of a product's reasons may be said here: the count only while the company keeps stock, the VAT only where its
 * regime charges it, and the cost only to whoever may read costs, as nothing else tells a cost (product.cost.read).
 */
final readonly class ProductCheckScope
{
    public function __construct(public bool $keepsStock, public bool $chargesVat, public bool $seesCosts)
    {
    }

    public function says(ProductCheckReason $reason): bool
    {
        return match ($reason) {
            ProductCheckReason::ToCount => $this->keepsStock,
            ProductCheckReason::VatMissing => $this->chargesVat,
            ProductCheckReason::CostMissing => $this->seesCosts,
            default => true,
        };
    }

    /** @return list<ProductCheckReason> in the order a screen lists them */
    public function reasons(): array
    {
        return array_values(array_filter(ProductCheckReason::cases(), $this->says(...)));
    }
}
