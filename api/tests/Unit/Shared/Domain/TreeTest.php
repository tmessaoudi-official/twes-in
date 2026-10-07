<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\Tree;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class TreeTest extends TestCase
{
    public function testAPickedNodeStandsForEveryNodeUnderItAtAnyDepth(): void
    {
        [$vehicle, $fuel, $diesel, $rent] = [Uuid::v7(), Uuid::v7(), Uuid::v7(), Uuid::v7()];
        $parents = [
            $vehicle->toRfc4122() => null,
            $fuel->toRfc4122() => $vehicle->toRfc4122(),
            $diesel->toRfc4122() => $fuel->toRfc4122(),
            $rent->toRfc4122() => null,
        ];

        self::assertSame(self::ids([$vehicle, $fuel, $diesel]), self::ids(Tree::withDescendants([$vehicle], $parents)));
        self::assertSame(self::ids([$fuel, $diesel]), self::ids(Tree::withDescendants([$fuel], $parents)));
        self::assertSame(self::ids([$rent, $fuel, $diesel]), self::ids(Tree::withDescendants([$rent, $fuel, $diesel], $parents)), 'each once');
        self::assertSame([], Tree::withDescendants([], $parents));
    }

    public function testANodeTheTreeDoesNotHoldStandsForItselfAlone(): void
    {
        $stranger = Uuid::v7();

        self::assertSame(self::ids([$stranger]), self::ids(Tree::withDescendants([$stranger], [])));
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function ids(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toRfc4122(), $ids);
    }
}
