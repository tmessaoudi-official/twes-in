<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

/** Where the database's write-ahead log stands with the backup layer (compose.backup.yaml), which ships it off-site. */
enum Archiving: string
{
    /** No backup layer on this machine: nothing is archived, and nothing should be. */
    case NotExpected = 'not expected';
    case Working = 'working';
    /** Switched on, yet nothing has reached the bucket since the server started. */
    case NeverArchived = 'never archived';
    /** The last attempt failed after the last success: the bucket, its keys or pgBackRest refuse. */
    case Failing = 'failing';
    /** The backup layer is meant to run, but the server does not archive at all. */
    case Off = 'off';
}
