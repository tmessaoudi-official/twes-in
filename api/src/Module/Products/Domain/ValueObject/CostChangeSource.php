<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain\ValueObject;

/**
 * What moved a product's cost: it was set when the product was created, a person edited it, a receipt applied one, or
 * a file read in gave it. A past purchase read from a file moves nothing: it is a line of the product's history, what it
 * was bought for and from whom, beside the costs it had.
 */
enum CostChangeSource: string
{
    case Created = 'created';
    case Edited = 'edited';
    case Receipt = 'receipt';
    case Import = 'import';
    case Purchase = 'purchase';
}
