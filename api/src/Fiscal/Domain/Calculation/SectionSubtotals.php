<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/**
 * A document's sections, from its lines: a line carrying a title opens a section, which runs to the next titled line,
 * and the lines before the first title are in none. A section's subtotal adds its lines' nets exactly: it sums figures
 * the calculator already worked out (or an issued document froze), so it is no figure of its own and is read again on
 * every read rather than kept.
 */
final class SectionSubtotals
{
    /**
     * @param list<array{string|null, string}> $lines each line's title (null for none) and its net, in order
     * @param int|null                         $scale the currency's; null for nets already written at it, whose decimals the sum keeps
     *
     * @return list<SectionSubtotal>
     */
    public static function of(array $lines, ?int $scale = null): array
    {
        $sections = [];
        $open = null;
        foreach ($lines as $index => [$title, $net]) {
            if (null !== $title) {
                if (null !== $open) {
                    $sections[] = self::closed($open, $scale);
                }
                $open = ['title' => $title, 'first' => $index, 'nets' => []];
            }
            if (null !== $open) {
                $open['nets'][] = Decimal::of($net);
            }
        }
        if (null !== $open) {
            $sections[] = self::closed($open, $scale);
        }

        return $sections;
    }

    /** @param list<SectionSubtotal> $sections */
    public static function startingAt(array $sections, int $line): ?SectionSubtotal
    {
        return array_find($sections, static fn (SectionSubtotal $section): bool => $section->firstLine === $line);
    }

    /** @param list<SectionSubtotal> $sections */
    public static function endingAt(array $sections, int $line): ?SectionSubtotal
    {
        return array_find($sections, static fn (SectionSubtotal $section): bool => $section->lastLine() === $line);
    }

    /** @param array{title: string, first: int, nets: list<\BcMath\Number>} $open */
    private static function closed(array $open, ?int $scale): SectionSubtotal
    {
        $sum = Decimal::sum($open['nets']);

        return new SectionSubtotal($open['title'], $open['first'], \count($open['nets']), null === $scale ? (string) $sum : Decimal::format($sum, $scale));
    }
}
