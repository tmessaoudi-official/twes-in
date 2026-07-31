<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document;

use Twes\Domain\Money\Money;

/**
 * The computed figures for one document. Immutable, and produced only by {@see DocumentCalculator}.
 *
 * Every figure a document displays or transmits is here, so that nothing downstream recomputes one — a
 * second implementation of any of these formulas is the defect CLAUDE.md § Architecture forbids by name.
 */
final readonly class DocumentTotals
{
    /**
     * @param list<Money> $lineNets each line's own net, in document order
     * @param list<VatGroup> $vatByRate the breakdown, ordered by first appearance of the rate
     */
    public function __construct(
        private array $lineNets,
        private Money $subtotalNet,
        private array $vatByRate,
        private Money $vatTotal,
        private Money $fixedChargesTotal,
        private Money $total,
    ) {}

    /** @return list<Money> */
    public function lineNets(): array
    {
        return $this->lineNets;
    }

    public function subtotalNet(): Money
    {
        return $this->subtotalNet;
    }

    /** @return list<VatGroup> */
    public function vatByRate(): array
    {
        return $this->vatByRate;
    }

    public function vatTotal(): Money
    {
        return $this->vatTotal;
    }

    public function fixedChargesTotal(): Money
    {
        return $this->fixedChargesTotal;
    }

    public function total(): Money
    {
        return $this->total;
    }
}
