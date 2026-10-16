<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/** The record was saved by someone else since the version this save started from. */
final class RecordChanged extends \RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('The record was saved since this version was read.', 0, $previous);
    }
}
