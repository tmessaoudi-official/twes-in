<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/** When a series' sequence starts again at one: on the first number of a new year, of a new month, or never. */
enum ResetPeriod: string
{
    case Yearly = 'yearly';
    case Monthly = 'monthly';
    case Never = 'never';
}
