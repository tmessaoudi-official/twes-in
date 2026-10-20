<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Application;

/** What a row naming a record means to deleting that record. */
enum RecordUse: string
{
    /** The record is used: the delete is refused. */
    case Uses = 'uses';
    /** The row goes with the record and comes back with it. */
    case Owned = 'owned';
    /** The row stays, its column emptied, and is filled again by the restore. */
    case Cleared = 'cleared';
    /** The column never names the subject, or only as history. */
    case Ignored = 'ignored';
}
