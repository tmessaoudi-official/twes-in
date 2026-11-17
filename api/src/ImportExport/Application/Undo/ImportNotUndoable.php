<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ImportExport\Application\Undo;

/** An import « Annuler cet import » no longer takes back: already taken back, or its day is over. */
final class ImportNotUndoable extends \RuntimeException
{
    public const string UNDONE = 'import_undone';
    public const string FINAL = 'import_final';
    /** Stock others stand on since, where the company refuses such an undo whole (import.undo_stock_conflict). */
    public const string STOCK_STANDS = 'import_stock_stands';

    /**
     * @param self::UNDONE|self::FINAL|self::STOCK_STANDS                      $reason
     * @param list<array{reference: string, location: string, reason: string}> $stands each product and place in the way
     */
    public function __construct(public readonly string $reason, string $message, public readonly array $stands = [])
    {
        parent::__construct($message);
    }
}
