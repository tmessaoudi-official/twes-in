<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\Erasure;

use App\Erasure\Application\DeclaresErasure;
use App\Erasure\Application\ErasureReference;

/** A product's cost history is kept whole: each change names what caused it, which no part of this slice erases. */
final readonly class ProductErasureReferences implements DeclaresErasure
{
    public function steps(): array
    {
        return [];
    }

    public function references(): array
    {
        return [ErasureReference::ignore('product_cost_change', 'source_id', null, 'A cost changes from a receipt, a movement or a hand edit, never a draft, a quote or the plan.')];
    }

    public function files(): array
    {
        return [];
    }
}
