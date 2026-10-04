<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Domain;

/**
 * Where an instrument received stands (docs/SPEC.md § 7, 2026-09-21 18:40): held in the portfolio, handed to the bank
 * for collection, then cashed (and only then a payment) or unpaid. Cashed and unpaid are final.
 */
enum InstrumentStatus: string
{
    case Held = 'held';
    case Deposited = 'deposited';
    case Cashed = 'cashed';
    case Unpaid = 'unpaid';

    /** Whether the instrument still promises money: a held or deposited one counts against what is due. */
    public function isOpen(): bool
    {
        return self::Held === $this || self::Deposited === $this;
    }
}
