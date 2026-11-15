<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\Storage;

/**
 * A write the disk had no room for: the installation's state, said to the person in words (`StorageFullListener`) and to
 * the people who look after it, never an unknown failure. Thrown by a storage adapter when its system said so.
 */
final class StorageFull extends \RuntimeException
{
    /** Whether a failed write's message is the system saying it had no room: ENOSPC, or PHP's short write. */
    public static function saidIn(string $message): bool
    {
        return 1 === preg_match('/errno=28 |No space left on device|out of free disk space/', $message);
    }
}
