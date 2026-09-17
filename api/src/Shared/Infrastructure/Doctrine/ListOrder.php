<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use Doctrine\ORM\QueryBuilder;

/**
 * The order a paged list asks for (docs/SPEC.md § 7, lists at scale). A row with nothing in the sorted column comes
 * last whichever the direction, where PostgreSQL alone would put it first when descending; DQL has no NULLS LAST, so a
 * hidden CASE sorts ahead of the column. A last expression, the row's number, breaks every tie so a page never shifts.
 */
final class ListOrder
{
    /**
     * @param array<string, 'asc'|'desc'> $order    what the request asked for, in the order it applies
     * @param array<string, string>       $columns  the DQL expression each sort key reads
     * @param list<string>                $nullable the sort keys whose column may be empty
     */
    public static function apply(QueryBuilder $query, array $order, array $columns, array $nullable, string $tieBreak): QueryBuilder
    {
        foreach ($order as $sort => $direction) {
            $column = $columns[$sort] ?? throw new \InvalidArgumentException("This list is not sorted by $sort.");
            if (\in_array($sort, $nullable, true)) {
                $alias = 'empty_'.$sort;
                $query->addSelect("CASE WHEN $column IS NULL THEN 1 ELSE 0 END AS HIDDEN $alias")->addOrderBy($alias, 'ASC');
            }
            $query->addOrderBy($column, $direction);
        }

        return $query->addOrderBy($tieBreak, 'ASC');
    }
}
