<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/** A row the subject refuses, naming the column at fault when there is one; the rest of the file is still read. */
final class RowRejected extends \DomainException
{
    public function __construct(
        public readonly ?string $column,
        string $message,
    ) {
        parent::__construct($message);
    }
}
