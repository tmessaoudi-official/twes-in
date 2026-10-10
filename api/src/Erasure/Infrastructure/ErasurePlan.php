<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Infrastructure;

use App\Erasure\Application\ErasedRows;
use App\Erasure\Application\ErasureReference;

/**
 * The steps of an erasure in the order their rows are picked: a step after the step its rows belong to, and after every
 * step erasing a table whose rows keep its own (the invoices that keep the quotes they name are picked before the
 * quotes). Rows are copied back in this order, and taken in the reverse one.
 */
final readonly class ErasurePlan
{
    /** @var list<ErasedRows> */
    public array $steps;

    /**
     * @param list<ErasedRows>       $steps
     * @param list<ErasureReference> $references
     */
    public function __construct(array $steps, public array $references)
    {
        $ordered = [];
        $visiting = [];
        $visit = function (ErasedRows $step) use (&$visit, &$ordered, &$visiting, $steps, $references): void {
            $key = spl_object_id($step);
            if (isset($ordered[$key])) {
                return;
            }
            if (isset($visiting[$key])) {
                throw new \LogicException(\sprintf('The rows of %s are kept by rows that are themselves kept by them: an erasure cannot pick either first.', $step->table));
            }
            $visiting[$key] = true;
            if (null !== $step->parent) {
                $visit(\in_array($step->parent, $steps, true) ? $step->parent : throw new \LogicException(\sprintf('The rows of %s belong to rows of %s that no step takes.', $step->table, $step->parent->table)));
            }
            foreach ($references as $reference) {
                if (ErasureReference::KEEP === $reference->kind && $reference->target === $step->table) {
                    foreach ($steps as $keeper) {
                        if ($keeper->table === $reference->table) {
                            $visit($keeper);
                        }
                    }
                }
            }
            unset($visiting[$key]);
            $ordered[$key] = $step;
        };
        foreach ($steps as $step) {
            $visit($step);
        }
        $this->steps = array_values($ordered);
    }

    /** @return list<int> the positions of the steps erasing this table */
    public function stepsOn(string $table): array
    {
        return array_keys(array_filter($this->steps, static fn (ErasedRows $step): bool => $step->table === $table));
    }

    public function positionOf(ErasedRows $step): int
    {
        $position = array_search($step, $this->steps, true);

        return \is_int($position) ? $position : throw new \LogicException(\sprintf('A step on %s is not in this plan.', $step->table));
    }

    /** @return list<ErasureReference> the references keeping rows of this table */
    public function keeping(string $table): array
    {
        return array_values(array_filter($this->references, static fn (ErasureReference $reference): bool => ErasureReference::KEEP === $reference->kind && $reference->target === $table));
    }

    /** @return list<ErasureReference> the links this plan cuts: columns of rows that stay, naming rows it takes */
    public function links(): array
    {
        return array_values(array_filter($this->references, fn (ErasureReference $reference): bool => ErasureReference::LINK === $reference->kind && null !== $reference->target && [] !== $this->stepsOn($reference->target)));
    }
}
