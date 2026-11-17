<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Notation;

/**
 * In which order a company's CSV files write a day's parts between slashes (import.day_order): day first, as French and
 * Tunisian people write one, month first, or year first. A day written as the field keeps it (2026-03-15) is read in
 * every order.
 */
enum DayOrder: string
{
    case DayFirst = 'day_first';
    case MonthFirst = 'month_first';
    case YearFirst = 'year_first';
}
