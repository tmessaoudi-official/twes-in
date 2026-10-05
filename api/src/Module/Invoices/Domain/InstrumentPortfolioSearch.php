<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

use App\Shared\Domain\DateRange;
use App\Shared\Domain\DecimalRange;
use Symfony\Component\Uid\Uuid;

/**
 * What the portfolio of cheques and traites asks for (docs/SPEC.md § 7, 2026-09-21 18:40; combinable filters,
 * 2026-10-06): the statuses, the kinds and the customers to list, none meaning every one, the due day and the amount as
 * intervals, and the order, the nearest due day first when it says nothing. The values of one filter are OR'd and
 * different filters are AND'd.
 */
final readonly class InstrumentPortfolioSearch
{
    public const array SORTS = ['dueOn', 'amount', 'status'];

    /**
     * @param list<InstrumentStatus>      $statuses
     * @param list<InstrumentKind>        $kinds
     * @param list<Uuid>                  $customers the customers of the invoices the instruments were handed over for
     * @param array<string, 'asc'|'desc'> $order     one of SORTS per key, in the order it applies
     */
    public function __construct(
        public array $statuses = [],
        public array $order = [],
        public array $kinds = [],
        public array $customers = [],
        public ?DateRange $dueOn = null,
        public ?DecimalRange $amount = null,
    ) {
    }

    /** The instruments still promising money: what a person has in hand or at the bank. */
    public static function open(): self
    {
        return new self([InstrumentStatus::Held, InstrumentStatus::Deposited]);
    }
}
