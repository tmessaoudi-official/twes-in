<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

/** The permissions behind every customers endpoint: reading customers, groups and contacts, and changing them. */
final class CustomerPermission
{
    public const string READ = 'customer.read';
    public const string WRITE = 'customer.write';
}
