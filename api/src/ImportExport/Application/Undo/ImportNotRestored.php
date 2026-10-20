<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

/** What an import's undo took back cannot be put back: goods counted or gone out since stand in its way. */
final class ImportNotRestored extends \RuntimeException
{
    /** @param array<string, string> $params */
    public function __construct(public readonly string $reason, string $message, public readonly array $params = [])
    {
        parent::__construct($message);
    }
}
