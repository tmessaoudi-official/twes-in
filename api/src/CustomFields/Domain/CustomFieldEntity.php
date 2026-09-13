<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\CustomFields\Domain;

/** The kinds of record a company may add fields to; products and documents join with their goals. */
enum CustomFieldEntity: string
{
    case Customer = 'customer';
}
