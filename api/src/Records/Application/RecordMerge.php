<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/** What a row naming a record does when that record is merged into another. */
enum RecordMerge: string
{
    /** It keeps naming the merged-away record, which stays as an alias of the one kept: a sealed row. */
    case Stays = 'stays';
    /** It is pointed at the record kept, and pointed back by the merge's undo. */
    case Moves = 'moves';
    /** As Moves, but a row the record kept already has its twin of is not moved: the pair is unique. */
    case MovesOrDrops = 'moves_or_drops';
    /** The column never names the subject, or only as history. */
    case Ignored = 'ignored';
}
