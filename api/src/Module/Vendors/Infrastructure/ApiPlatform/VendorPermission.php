<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

/** The permissions behind every vendors endpoint: reading vendors and changing them. */
final class VendorPermission
{
    public const string READ = 'vendor.read';
    public const string WRITE = 'vendor.write';
}
