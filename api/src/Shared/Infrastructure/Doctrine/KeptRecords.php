<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

/**
 * The SQL that reads a merged record as the one kept. A record merged away stays, switched off and pointing at the one
 * kept through `merged_into_id`, and what a document or a movement names keeps naming it; every figure, list and drill
 * then reads the one kept. Written here once, so a statement cannot fold one subject and forget another:
 * `MergedRecordFoldTest` refuses the column anywhere else a statement is written.
 *
 * Every name is checked as an identifier before it is written into a statement, so nothing a request sends can reach
 * one; the values themselves stay bound parameters.
 */
final class KeptRecords
{
    /** The tables whose records are merged into one kept (the alias model). */
    private const array MERGED = ['customer', 'product', 'vendor'];

    private const string IDENTIFIER = '/^[a-z][a-z0-9_]*$/';

    private const string COLUMN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /** The id of the record kept for the row `$alias` of a merged table: the one it was merged into, or its own. */
    public static function id(string $alias): string
    {
        $alias = self::identifier($alias);

        return \sprintf('COALESCE(%1$s.merged_into_id, %1$s.id)', $alias);
    }

    /**
     * Joins the record `$column` names as `$named`, then the record kept for it as `$kept`, whose columns the statement
     * reads. A LEFT JOIN for a column that may be empty, a JOIN when `$required`.
     */
    public static function join(string $table, string $column, string $kept, string $named = 'named', bool $required = false): string
    {
        $table = self::table($table);
        $column = self::column($column);
        $kept = self::identifier($kept);
        $named = self::identifier($named);
        if ($kept === $named) {
            throw new \InvalidArgumentException(\sprintf('The record named and the record kept need two aliases, "%s" was given for both.', $kept));
        }
        $join = $required ? 'JOIN' : 'LEFT JOIN';

        return \sprintf('%1$s %2$s %3$s ON %3$s.id = %4$s %1$s %2$s %5$s ON %5$s.id = %6$s', $join, $table, $named, $column, $kept, self::id($named));
    }

    /**
     * A condition: `$column` names the record kept, or one merged into it. `$kept` is a bound parameter (`:customer`)
     * or a column of the statement (`p.id`); with `$list` a parameter bound to a list of the records kept. Merges are one
     * level deep (merging the record kept points its own aliases at the new one), so the record kept and its aliases are
     * read through the primary key and the `merged_into_id` index rather than a COALESCE no index serves.
     */
    public static function among(string $column, string $table, string $kept, bool $list = false): string
    {
        $kept = self::kept($kept, $list);
        $test = $list ? 'IN (%1$s)' : '= %1$s';

        return \sprintf(
            '%s IN (SELECT stands.id FROM %s stands WHERE stands.id %s OR stands.merged_into_id %s)',
            self::column($column),
            self::table($table),
            \sprintf($test, $kept),
            \sprintf($test, $kept),
        );
    }

    /** A condition: the row `$alias` was merged into another. */
    public static function mergedAway(string $alias): string
    {
        return self::identifier($alias).'.merged_into_id IS NOT NULL';
    }

    private static function kept(string $kept, bool $list): string
    {
        if (1 === preg_match('/^:[a-z][a-z0-9_]*$/', $kept)) {
            return $kept;
        }
        if (!$list && 1 === preg_match(self::COLUMN, $kept)) {
            return $kept;
        }

        throw new \InvalidArgumentException(\sprintf('"%s" is neither a bound parameter nor a column written as alias.column.', $kept));
    }

    private static function table(string $table): string
    {
        if (!\in_array($table, self::MERGED, true)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is no table whose records are merged into one kept.', $table));
        }

        return $table;
    }

    private static function identifier(string $name): string
    {
        if (1 !== preg_match(self::IDENTIFIER, $name)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not an alias or a parameter name.', $name));
        }

        return $name;
    }

    private static function column(string $column): string
    {
        if (1 !== preg_match(self::COLUMN, $column)) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not a column written as alias.column.', $column));
        }

        return $column;
    }
}
