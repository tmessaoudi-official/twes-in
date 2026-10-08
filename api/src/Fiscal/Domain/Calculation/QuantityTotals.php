<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

use App\Fiscal\Domain\Unit;

/**
 * The « Quantités » line under a document's lines: how much it carries in each unit, so a delivery is counted at a
 * glance. Quantities are added as decimals, never as floats. A single line already says it, so it adds up nothing.
 */
final class QuantityTotals
{
    /**
     * The same, from each line's unit and quantity.
     *
     * @param list<array{Unit, string}> $lines
     *
     * @return list<QuantityTotal>
     */
    public static function ofUnits(array $lines): array
    {
        return self::of(array_map(static fn (array $line): array => [
            'unit' => $line[0]->getId()->toRfc4122(),
            'name' => $line[0]->getName(),
            'decimals' => $line[0]->getDecimals(),
            'quantity' => $line[1],
        ], $lines));
    }

    /**
     * @param list<array{unit: string, name: string, decimals: int, quantity: string}> $lines in the document's order,
     *                                                                                        the unit by its identifier
     *
     * @return list<QuantityTotal> in the order the units first appear
     */
    public static function of(array $lines): array
    {
        if (\count($lines) < 2) {
            return [];
        }
        $byUnit = [];
        foreach ($lines as $line) {
            $byUnit[$line['unit']] ??= ['name' => $line['name'], 'decimals' => $line['decimals'], 'quantities' => []];
            $byUnit[$line['unit']]['quantities'][] = Decimal::of($line['quantity']);
        }

        return array_values(array_map(
            static fn (array $unit): QuantityTotal => new QuantityTotal($unit['name'], $unit['decimals'], Decimal::format(Decimal::sum($unit['quantities']), $unit['decimals'])),
            $byUnit,
        ));
    }
}
