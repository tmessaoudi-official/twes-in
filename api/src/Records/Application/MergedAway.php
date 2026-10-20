<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/** What becomes of a record merged into another, once every row that moves names the one kept. */
enum MergedAway: string
{
    /** It stays, switched off, pointing at the record kept: issued documents and movements keep naming it. */
    case Aliased = 'aliased';
    /** It goes, as a delete takes it, and comes back with the merge's undo: nothing it named stays with it. */
    case Removed = 'removed';
}
