<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * How strongly a document's state prints across it. Each is a share of the red the word is drawn in, never so faint that
 * a draft or a duplicate could pass for the original.
 */
enum WatermarkIntensity: string
{
    /** Below it, a state photocopied or scanned in grey can vanish from the page. */
    public const int FLOOR_PERCENT = 8;

    case Discreet = 'discreet';
    /** What documents always printed in. */
    case Normal = 'normal';
    case Marked = 'marked';

    public function percent(): int
    {
        return match ($this) {
            self::Discreet => 10,
            self::Normal => 14,
            self::Marked => 22,
        };
    }
}
