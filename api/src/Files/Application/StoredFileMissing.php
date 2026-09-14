<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Application;

/** The storage has no bytes under a key a record names. */
final class StoredFileMissing extends \RuntimeException
{
}
