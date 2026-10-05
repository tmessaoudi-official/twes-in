<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/**
 * What the portfolio of cheques and traites asks for (docs/SPEC.md § 7, 2026-09-21 18:40): the statuses to list, none
 * meaning every one, and the order, the nearest due day first when it says nothing.
 */
final readonly class InstrumentPortfolioSearch
{
    public const array SORTS = ['dueOn', 'amount', 'status'];

    /**
     * @param list<InstrumentStatus>      $statuses
     * @param array<string, 'asc'|'desc'> $order    one of SORTS per key, in the order it applies
     */
    public function __construct(public array $statuses = [], public array $order = [])
    {
    }

    /** The instruments still promising money: what a person has in hand or at the bank. */
    public static function open(): self
    {
        return new self([InstrumentStatus::Held, InstrumentStatus::Deposited]);
    }
}
