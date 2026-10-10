<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

/**
 * What an erasure does about a column that names rows a part may take, declared by the module owning that column:
 * - keep: a row it names is kept, not taken, while a row that stays names it (the invoice a recurring invoice copies);
 * - link: the column of a row that stays is emptied, and filled again by the undo (a place's drawing on the plan);
 * - ignore: it never names a row a part takes, and says why.
 */
final readonly class ErasureReference
{
    public const string KEEP = 'keep';
    public const string LINK = 'link';
    public const string IGNORE = 'ignore';

    /** @param ?string $target the table it names, null for a column naming rows of several (an attachment's subject) */
    private function __construct(public string $kind, public string $table, public string $column, public ?string $target, public string $why)
    {
        foreach ([$table, $column, $target ?? 'any'] as $name) {
            if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a name the erasure may write into SQL.', $name));
            }
        }
    }

    public static function keep(string $table, string $column, string $target, string $why): self
    {
        return new self(self::KEEP, $table, $column, $target, $why);
    }

    public static function link(string $table, string $column, string $target, string $why): self
    {
        return new self(self::LINK, $table, $column, $target, $why);
    }

    public static function ignore(string $table, string $column, ?string $target, string $why): self
    {
        return new self(self::IGNORE, $table, $column, $target, $why);
    }
}
