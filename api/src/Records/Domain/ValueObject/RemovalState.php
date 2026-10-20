<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Domain\ValueObject;

enum RemovalState: string
{
    /** Gone, and may come back until its day ends. */
    case Pending = 'pending';
    case Restored = 'restored';
    /** Its day ended: the copy went, and nothing comes back. */
    case Final = 'final';
}
