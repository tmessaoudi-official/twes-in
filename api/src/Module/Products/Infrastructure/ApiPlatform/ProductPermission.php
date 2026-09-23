<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

/**
 * The permissions behind every products endpoint: reading products and their categories, and changing them; and
 * reading what a product costs the company and the codes its suppliers print, without which the API sends neither
 * (docs/SPEC.md § 7, 2026-09-23 09:45, slice 5).
 */
final class ProductPermission
{
    public const string READ = 'product.read';
    public const string WRITE = 'product.write';
    public const string COST_READ = 'product.cost.read';
}
