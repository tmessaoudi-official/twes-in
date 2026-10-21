<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain\ValueObject;

/**
 * Why a customer is « À vérifier » without anyone marking it, clearing itself once fixed: a business in the company's
 * country without a number its preset requires, which a file may leave out and no invoice may.
 */
enum CustomerCheckReason: string
{
    case IdentifierMissing = 'identifier_missing';
}
