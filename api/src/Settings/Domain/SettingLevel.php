<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Settings\Domain;

/**
 * Every level a setting can be stored at, across the three chains (docs/SPEC.md § 3 Settings). A level is added
 * here when a chain gains one; the stored rows name levels by these values, so a value is never renamed.
 */
enum SettingLevel: string
{
    case Platform = 'platform';
    case Company = 'company';
    case CustomerGroup = 'customer_group';
    case Customer = 'customer';
    case Document = 'document';
    case ProductCategory = 'product_category';
    case Product = 'product';
    case DocumentLine = 'document_line';
    case Role = 'role';
    case User = 'user';
}
