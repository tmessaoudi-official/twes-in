<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\Security;

/**
 * The permissions behind every inventory endpoint: reading stock and its locations, and moving stock or arranging
 * locations; and confirming a sale beyond the stock recorded, where « Sortie sans stock enregistré » asks for it, which
 * `ShortSaleConsent::PERMISSION` names for the documents that sell.
 */
final class StockPermission
{
    public const string READ = 'stock.read';
    public const string WRITE = 'stock.write';
    public const string SELL_SHORT = 'stock.sell_short';
}
