<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

/**
 * What the person receiving asks the products' cost to become, where the company leaves that choice to them: the
 * weighted average of what is on hand, or the cost this receipt came at. The company's own setting decides otherwise.
 */
enum IntakeCost: string
{
    case Average = 'average';
    case Last = 'last';
}
