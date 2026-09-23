<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

/**
 * How a product's stock is told apart (docs/SPEC.md § 7, 2026-09-22 11:10): not at all, by lot — two drums of one glue
 * that expire a month apart — or one piece at a time by its serial number.
 */
enum ProductTracking: string
{
    case None = 'none';
    case Lot = 'lot';
    case Serial = 'serial';
}
