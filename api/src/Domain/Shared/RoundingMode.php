<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Shared;

/**
 * How to resolve precision that cannot be kept.
 *
 * Every operation on {@see Money} that can lose a digit demands one of these explicitly. There is no
 * default, because "whatever rounding the language happens to do" is how a billing system ends up
 * with a total that disagrees with the sum of its own lines.
 */
enum RoundingMode
{
    /** Away from zero when anything at all is discarded. */
    case Up;

    /** Toward zero, discarding the remainder. */
    case Down;

    /** Toward positive infinity. */
    case Ceiling;

    /** Toward negative infinity. */
    case Floor;

    /** Nearest; a tie goes away from zero. The everyday commercial default. */
    case HalfUp;

    /** Nearest; a tie goes toward zero. */
    case HalfDown;

    /**
     * Nearest; a tie goes to the even neighbour — "banker's rounding".
     *
     * Over many roundings this does not drift upward the way HalfUp does, which is why some
     * jurisdictions and most financial reporting prefer it.
     */
    case HalfEven;

    /**
     * Refuse to round at all.
     *
     * The operation succeeds only if it loses nothing. Use it where a lost millime is a defect rather
     * than a rounding: reconciliation, ledger splits, anything that must balance to the digit.
     */
    case Unnecessary;
}
