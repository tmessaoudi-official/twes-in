<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\ValueObject;

/**
 * What someone decides about a price a cost moved: the price that keeps the margin, the price kept as it is, or, for a
 * price that followed its cost, the price the move replaced put back (« Annuler »).
 */
enum PriceReviewDecision: string
{
    case Adjust = 'adjust';
    case Keep = 'keep';
    case Restore = 'restore';
}
