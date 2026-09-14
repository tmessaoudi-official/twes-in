<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

/** The permissions behind every products endpoint: reading products and their categories, and changing them. */
final class ProductPermission
{
    public const string READ = 'product.read';
    public const string WRITE = 'product.write';
}
