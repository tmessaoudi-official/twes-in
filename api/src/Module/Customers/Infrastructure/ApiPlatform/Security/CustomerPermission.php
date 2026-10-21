<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform\Security;

/**
 * The permissions behind every customers endpoint: reading customers, groups and contacts, changing them, and deleting
 * or merging them. Removing is its own permission so a clerk adds a customer at the counter while a duplicate it makes is
 * merged by a manager.
 */
final class CustomerPermission
{
    public const string READ = 'customer.read';
    public const string WRITE = 'customer.write';
    public const string REMOVE = 'customer.remove';
}
