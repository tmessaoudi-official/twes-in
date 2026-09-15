<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

/** The permissions behind every inventory endpoint: reading stock and its locations, and moving stock or arranging locations. */
final class StockPermission
{
    public const string READ = 'stock.read';
    public const string WRITE = 'stock.write';
}
