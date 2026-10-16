<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\ValueObject;

/**
 * What a credit note does with the stamp its invoice charged, a country's rule its fiscal preset states: given back
 * once, by the credit note completing the invoice, or kept by the invoice whatever corrects it. Never given back more
 * than once whichever: a stamp is charged once, on the invoice.
 */
enum StampOnCorrection: string
{
    case GivenBackOnce = 'given_back_once';
    case Kept = 'kept';
}
