<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

/** When a series' sequence starts again at one; the allocation that applies it arrives with the first numbered document (G6). */
enum ResetPeriod: string
{
    case Yearly = 'yearly';
    case Monthly = 'monthly';
    case Never = 'never';
}
