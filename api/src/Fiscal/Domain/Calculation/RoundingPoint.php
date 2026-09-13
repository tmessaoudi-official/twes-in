<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/**
 * Where a percentage tax is rounded. Both presets choose per rate group, the order EN 16931 BR-CO-17 prescribes;
 * a company may choose per line once the settings engine carries the choice.
 */
enum RoundingPoint: string
{
    /** Summed over the lines sharing a tax, then rounded once; each line's share is allocated, never recomputed. */
    case PerRateGroup = 'per_rate_group';
    /** Rounded on each line, then summed. */
    case PerLine = 'per_line';
}
