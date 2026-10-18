<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\ApiPlatform\Security;

/**
 * The permissions behind every purchase orders endpoint: reading orders, writing them (drafting, sending, closing and
 * cancelling), and receiving the goods, which puts them on the shelves and is the counter's work as much as the
 * buyer's.
 */
final class PurchaseOrderPermission
{
    public const string READ = 'purchase.read';
    public const string WRITE = 'purchase.write';
    public const string RECEIVE = 'purchase.receive';
}
