<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Erasure\Application;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** The parts « Effacer des données » offers, in the page's order, and what every module declared about them. */
final readonly class ErasureCatalogue
{
    /** The parts built so far; the page lists them in this order. */
    public const array PARTS = ['stock_map', 'drafts'];

    /** @var list<DeclaresErasure> */
    private array $declarations;

    /** @param iterable<DeclaresErasure> $declarations */
    public function __construct(#[AutowireIterator('app.erasure.declarations')] iterable $declarations)
    {
        $this->declarations = array_values([...$declarations]);
        foreach ($this->declarations as $declaration) {
            foreach ($declaration->steps() as $step) {
                if (!\in_array($step->part, self::PARTS, true)) {
                    throw new \LogicException(\sprintf('%s erases rows of %s for a part no page offers: "%s".', $declaration::class, $step->table, $step->part));
                }
            }
        }
    }

    /** @return list<DeclaresErasure> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /**
     * The parts a request asked for, each once and known, in the page's order.
     *
     * @return list<string>
     *
     * @throws NoPartChosen
     * @throws UnknownErasurePart
     */
    public function chosen(mixed $parts): array
    {
        if (!\is_array($parts) || [] === $parts) {
            throw new NoPartChosen('Choose at least one part to erase.');
        }
        foreach ($parts as $part) {
            if (!\is_string($part) || !\in_array($part, self::PARTS, true)) {
                throw new UnknownErasurePart(\is_string($part) ? $part : get_debug_type($part));
            }
        }

        return array_values(array_intersect(self::PARTS, $parts));
    }

    /**
     * @param list<string> $parts
     *
     * @return list<ErasedRows>
     */
    public function stepsOf(array $parts): array
    {
        $steps = [];
        foreach ($this->declarations as $declaration) {
            foreach ($declaration->steps() as $step) {
                if (\in_array($step->part, $parts, true)) {
                    $steps[] = $step;
                }
            }
        }

        return $steps;
    }

    /** @return list<ErasureReference> */
    public function references(): array
    {
        return array_merge(...array_map(static fn (DeclaresErasure $declaration): array => $declaration->references(), $this->declarations));
    }

    /** @return list<NamedFile> */
    public function files(): array
    {
        return array_merge(...array_map(static fn (DeclaresErasure $declaration): array => $declaration->files(), $this->declarations));
    }

    /**
     * Every word a part's preview counts under, zero included, in the order declared.
     *
     * @return list<string>
     */
    public function countedIn(string $part): array
    {
        return array_values(array_unique(array_filter(array_map(static fn (ErasedRows $step): ?string => $step->counted, $this->stepsOf([$part])), static fn (?string $word): bool => null !== $word)));
    }

    /**
     * Counts as the page reads them: each part asked, in the page's order, each word it counts under, zero included.
     *
     * @param array<string, array<string, int>> $counted
     * @param list<string>                      $parts
     *
     * @return array<string, array<string, int>>
     */
    public function shaped(array $counted, array $parts): array
    {
        $shaped = [];
        foreach (array_intersect(self::PARTS, $parts) as $part) {
            $shaped[$part] = [];
            foreach ($this->countedIn($part) as $word) {
                $shaped[$part][$word] = $counted[$part][$word] ?? 0;
            }
        }

        return $shaped;
    }

    /**
     * The kinds open screens reload on once these parts go or come back.
     *
     * @param list<string> $parts
     *
     * @return list<string>
     */
    public function liveKindsOf(array $parts): array
    {
        $kinds = [];
        foreach ($this->stepsOf($parts) as $step) {
            if (null !== $step->live) {
                $kinds[] = $step->live;
            }
        }
        foreach ($this->references() as $reference) {
            if (ErasureReference::LINK === $reference->kind && \in_array($reference->target, array_map(static fn (ErasedRows $step): string => $step->table, $this->stepsOf($parts)), true)) {
                $kinds[] = $reference->table;
            }
        }

        return array_values(array_unique($kinds));
    }
}
