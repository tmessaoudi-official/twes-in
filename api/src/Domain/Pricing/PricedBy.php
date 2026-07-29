<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Pricing;

/**
 * Which of a product's two priceable fields the user actually typed.
 *
 * Persisted alongside the product, because it is not a UI detail: it decides which value is authoritative
 * and which is merely displayed, and therefore which one may be recomputed. Without it, both fields look
 * equally real and the derived one gets rebuilt from a rounded copy of the other.
 */
enum PricedBy: string
{
    /** The user typed a profit rate; the selling price follows from it. */
    case ProfitRate = 'profit_rate';

    /** The user typed a selling price; the profit rate is derived for display. */
    case NetPrice = 'net_price';
}
