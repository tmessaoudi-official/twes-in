<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\ValueObject;

/**
 * Why a credit note's figures leave out a fixed charge per document chosen on it, so a screen says only what holds:
 * the credit note completing the invoice gives it back, the country's rule keeps it with the invoice, or the invoice
 * never charged it (or has nothing of it left to give back).
 */
enum ChargeLeftOut: string
{
    case GivenBackByCompletingNote = 'given_back_by_completing_note';
    case Kept = 'kept';
    case NotCharged = 'not_charged';
}
