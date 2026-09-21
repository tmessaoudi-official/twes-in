<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Venue\Domain;

/**
 * Which way a rectangle is repeated across the floor — the four arrows beside the plan (docs/SPEC.md row 83, the
 * approved canvas's Repeat board).
 *
 * The axes are the FLOOR's, never the rectangle's own: a plan is read the way it is printed, so "vers le bas" means
 * down the page whatever angle the rack is set at. What the rotation changes is the SIZE of the step, not its
 * direction — see `DrawStockMap::repeat()`.
 */
enum PlanWay: string
{
    case Up = 'up';
    case Down = 'down';
    case Left = 'left';
    case Right = 'right';

    /** True when the step runs along the floor's y, which is what decides which side of the rectangle it takes. */
    public function isVertical(): bool
    {
        return self::Up === $this || self::Down === $this;
    }

    /** True when the step counts backwards towards the floor's origin, where leaving it is refused. */
    public function isBackwards(): bool
    {
        return self::Up === $this || self::Left === $this;
    }
}
