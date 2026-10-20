<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure;

use App\Erasure\Application\DeclaresErasure;
use App\Shared\Infrastructure\Doctrine\SchemaLinks;
use Doctrine\DBAL\Connection;

/**
 * What the database has that the erasure was not told about: a table no longer there that a part still erases, and a
 * column reaching a table a part erases that no step copies and no reference classifies. A column reaches a table by
 * its foreign key, or, with none, by its name (`model_invoice_id`, `spot_id` for `venue_spot`); a column that may name
 * rows of any table (`entity_id`, `source_id`) must be classified once, whatever it names.
 */
final class ErasureCoverage
{
    /** Columns naming a row of whichever table another column of theirs says. */
    private const array ANY_TABLE = ['entity_id', 'source_id', 'subject_id', 'record_id'];

    /**
     * @param iterable<DeclaresErasure> $declarations
     *
     * @return list<string>
     */
    public static function unaccounted(Connection $connection, iterable $declarations): array
    {
        $schema = new SchemaLinks($connection);
        $steps = [];
        $references = [];
        foreach ($declarations as $declaration) {
            $steps = [...$steps, ...$declaration->steps()];
            $references = [...$references, ...$declaration->references()];
        }
        /** @var array<string, string> $erased the part erasing each table */
        $erased = [];
        foreach ($steps as $step) {
            $erased[$step->table] ??= $step->part;
        }

        $out = [];
        $tables = $schema->tables();
        foreach ($steps as $step) {
            if (!\in_array($step->table, $tables, true)) {
                $out[] = \sprintf('%s no longer exists, and the part %s still erases it', $step->table, $step->part);
            }
        }

        $covered = [];
        foreach ($steps as $step) {
            if (null !== $step->parent && null !== $step->parentColumn) {
                $covered[] = "$step->table.$step->parentColumn>{$step->parent->table}";
                if (\in_array($step->parentColumn, self::ANY_TABLE, true)) {
                    $covered[] = "$step->table.$step->parentColumn>*";
                }
            }
        }
        foreach ($references as $reference) {
            $covered[] = "$reference->table.$reference->column>".($reference->target ?? '*');
        }
        $isCovered = static fn (string $table, string $column, string $target): bool => \in_array("$table.$column>$target", $covered, true) || \in_array("$table.$column>*", $covered, true);
        $unclassified = static fn (string $table, string $column, string $target) => \sprintf('%s.%s points at %s, which the part %s erases, and is neither copied nor classified', $table, $column, $target, $erased[$target]);

        $keyed = [];
        foreach ($schema->foreignKeys() as ['table' => $table, 'column' => $column, 'target' => $target]) {
            $keyed[] = "$table.$column";
            if (isset($erased[$target]) && !$isCovered($table, $column, $target)) {
                $out[] = $unclassified($table, $column, $target);
            }
        }

        foreach ($schema->columnsOfType('uuid') as ['table' => $table, 'column' => $column]) {
            if (\in_array("$table.$column", $keyed, true)) {
                continue;
            }
            if (\in_array($column, self::ANY_TABLE, true)) {
                if ([] !== $erased && !\in_array("$table.$column>*", $covered, true)) {
                    $out[] = \sprintf('%s.%s may name rows of any table, the erased ones included, and is not classified', $table, $column);
                }
                continue;
            }
            foreach (array_keys($erased) as $target) {
                if (SchemaLinks::namedAfter($column, $target) && !$isCovered($table, $column, $target)) {
                    $out[] = $unclassified($table, $column, $target);
                }
            }
        }

        return array_values(array_unique($out));
    }
}
