<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application;

/**
 * A row the subject refuses, naming the column at fault when there is one; the rest of the file is still read. The
 * reason is a stable code and its parameters, which the screen translates, beside the English message it shows for a
 * code it does not know yet (docs/SPEC.md § 7, 2026-09-19).
 */
final class RowRejected extends \DomainException
{
    /** @param array<string, string|int> $params */
    public function __construct(
        public readonly ?string $column,
        string $message,
        public readonly string $reason,
        public readonly array $params = [],
    ) {
        parent::__construct($message);
    }
}
