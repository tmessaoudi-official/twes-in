<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Application;

/** What a cost a receipt moves does to the product's price, which is the company's to set. */
enum PriceOnCostChange: string
{
    /** The price stays, and the product shows the margin that moved until someone adjusts the price or keeps it. */
    case Offer = 'offer';
    /** The price stays, and nothing is offered. */
    case Keep = 'keep';
    /** The price moves with the cost, keeping the margin. */
    case Follow = 'follow';
}
