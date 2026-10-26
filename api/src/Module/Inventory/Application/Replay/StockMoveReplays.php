<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Replay;

use App\Module\Inventory\Domain\Model\StockMoveFailure;

/**
 * Moves a document's stock again, the move its validation, cancellation or issue ran, from the document as it stands:
 * a move that already happened moves nothing more. Answered by the console commands' own replay.
 */
interface StockMoveReplays
{
    /** True once the stock has moved, now or before; false when it failed again, or the document is no longer there. */
    public function replay(StockMoveFailure $failure): bool;
}
