<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

/** Whom a company sells to: a business, which its preset may ask for registration numbers, or a private individual. */
enum CustomerKind: string
{
    case Company = 'company';
    case Individual = 'individual';
}
