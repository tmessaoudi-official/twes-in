<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Venue\Domain;

use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Venue\Domain\Exception\InvalidVenue;
use App\Venue\Domain\Model\VenueArea;
use App\Venue\Domain\ValueObject\PlanRect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * A floor refused says why on the field of its form: the floor's form shows its name, its level, its size and the plan
 * behind it, so a refusal naming anything else, or no reason, leaves the person with « Valeur refusée. » above the form.
 */
final class VenueAreaTest extends TestCase
{
    /** @return iterable<string, array{callable(VenueArea, \DateTimeImmutable): mixed, string, string, array<string, string|int>}> */
    public static function refusals(): iterable
    {
        yield 'a floor with no name' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->rename(' ', 0, $now), 'name', 'value_required', []];
        yield 'a floor named past the column' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->rename(str_repeat('é', VenueArea::NAME_MAX + 1), 0, $now), 'name', 'invalid_text', ['max' => VenueArea::NAME_MAX]];
        yield 'a level past the highest' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->rename('Étage', VenueArea::LEVEL_MAX + 1, $now), 'level', 'whole_number_between', ['min' => VenueArea::LEVEL_MIN, 'max' => VenueArea::LEVEL_MAX]];
        yield 'a plan more than opaque' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->showPlan(null, null, 101, $now), 'imageOpacity', 'whole_number_between', ['min' => 0, 'max' => 100]];
        yield 'a plan with no width' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->showPlan(Uuid::v7(), null, 40, $now), 'imageMetresWide', 'value_required', []];
        yield 'a plan of no width' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->showPlan(Uuid::v7(), '0', 40, $now), 'imageMetresWide', 'above_zero', []];
        yield 'a plan wider than the limit' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->showPlan(Uuid::v7(), '20000', 40, $now), 'imageMetresWide', 'at_most', ['max' => PlanRect::LIMIT]];
        yield 'a floor of no width' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->measure('0', '12', $now), 'widthMetres', 'above_zero', []];
        yield 'a floor\'s depth in words' => [static fn (VenueArea $floor, \DateTimeImmutable $now) => $floor->measure('24', 'douze', $now), 'depthMetres', 'not_a_number', []];
    }

    /**
     * @param callable(VenueArea, \DateTimeImmutable): mixed $change
     * @param array<string, string|int>                      $params
     */
    #[DataProvider('refusals')]
    public function testARefusedFloorSaysWhyOnTheFieldOfItsForm(callable $change, string $field, string $reason, array $params): void
    {
        $company = new Company('Quincaillerie Ben Ali', 'TN', 'TND', 'fr', 'Africa/Tunis', new \DateTimeImmutable());
        $now = new \DateTimeImmutable('2026-09-22 09:00:00');
        $floor = VenueArea::create(Establishment::create($company, '000', 'Bab Saadoun', true, $now), 'Rez-de-chaussée', 0, $now);

        try {
            $change($floor, $now);
            self::fail('The floor took the change.');
        } catch (InvalidVenue $refused) {
            self::assertSame([$field, $reason, $params], [$refused->field, $refused->reason, $refused->params]);
        }
    }
}
