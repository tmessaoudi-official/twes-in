<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

/** What the rule answers a document's sale, and the lines it would take short. */
final readonly class Shortfall
{
    /** @param list<ShortLine> $lines */
    public function __construct(public ShortSaleRule $rule, public array $lines = [])
    {
    }

    /** Null when the sale may go on: nothing short, the rule allows it, or it is confirmed by whoever may confirm it. */
    public function refusal(ShortSaleConsent $consent): ?ShortOfStock
    {
        if ([] === $this->lines || ShortSaleRule::Allow === $this->rule) {
            return null;
        }
        $confirmable = ShortSaleRule::Confirm === $this->rule && $consent->mayConfirm;
        if ($confirmable && $consent->confirmed) {
            return null;
        }

        return new ShortOfStock($this->lines, $confirmable);
    }
}
