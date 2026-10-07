<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Domain;

use Symfony\Component\Uid\Uuid;

/**
 * A pick in a tree of records (expense categories, stock locations) stands for the record and everything under it, at
 * any depth (docs/SPEC.md § 7, row 197): « Véhicule » lists what was filed under « Carburant » too.
 */
final class Tree
{
    /**
     * @param list<Uuid>                 $picked
     * @param array<string, string|null> $parents each record's parent, by id; a record not in it stands for itself alone
     *
     * @return list<Uuid> the picked records, then what sits under them, each once
     */
    public static function withDescendants(array $picked, array $parents): array
    {
        $children = [];
        foreach ($parents as $id => $parent) {
            if (null !== $parent) {
                $children[$parent][] = (string) $id;
            }
        }
        $found = [];
        $waiting = array_map(static fn (Uuid $id): string => $id->toRfc4122(), $picked);
        while ([] !== $waiting) {
            $id = array_shift($waiting);
            if (isset($found[$id])) {
                continue;
            }
            $found[$id] = Uuid::fromString($id);
            array_push($waiting, ...($children[$id] ?? []));
        }

        return array_values($found);
    }
}
