<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

/** A movement whose cost was not left to a cost reader takes none afterwards: what it is worth is already known. */
final class StockMovementCostKnown extends \DomainException
{
}
