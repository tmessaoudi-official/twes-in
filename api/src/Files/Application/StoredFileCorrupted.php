<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

/** The bytes stored under a record's key are no longer the ones it was made for; they are never served. */
final class StoredFileCorrupted extends \RuntimeException
{
}
