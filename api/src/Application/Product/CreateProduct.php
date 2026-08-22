<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Product;

use Twes\Domain\Pricing\ProductPricing;
use Twes\Domain\Pricing\Rate;

/**
 * Create a catalogue product for the calling tenant.
 *
 * **THE PRICING ARRIVES ALREADY BUILT, and that is the whole point of this command's shape.** F4 rules that a
 * product is priced by EITHER a typed profit rate OR a typed net price, never both, and {@see ProductPricing}
 * expresses that with two named constructors and no way to build one without choosing. So the transport chooses
 * — it is the layer that knows which field arrived — and by the time a command exists the choice is already
 * made and unforgeable. A command carrying `?Rate $profitRate, ?Money $netPrice` would reintroduce exactly the
 * both-or-neither state the domain type was designed to make unrepresentable.
 *
 * **NO TENANT FIELD and NO ID FIELD**, for the reasons {@see \Twes\Application\Client\CreateClient} sets out:
 * tenancy is ambient context rather than data, and identity is the handler's decision.
 */
final readonly class CreateProduct
{
    public function __construct(
        public string $name,
        public ?string $sku,
        public ProductPricing $pricing,
        public Rate $vatRate,
    ) {}
}
