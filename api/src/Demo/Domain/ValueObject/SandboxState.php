<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Domain\ValueObject;

/**
 * Where a demo sandbox stands: waiting for the worker, being written by it, ready for a visitor, then that visitor's.
 * A build that stopped halfway stays `Building`, so a message handed over again never writes it a second time.
 */
enum SandboxState: string
{
    case Queued = 'queued';
    case Building = 'building';
    case Ready = 'ready';
    case Claimed = 'claimed';
}
