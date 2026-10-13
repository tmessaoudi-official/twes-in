<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\StepUp;

use App\Identity\Infrastructure\StepUp\StepUpBounds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * The step-up's two numbers are a deployment's to set (docs/SPEC.md § 7: how long a proof lasts, how many wrong
 * answers a sign-in may give), within bounds: a proof held for a day or a hundred guesses would undo what the list is for,
 * and a minute or one typo would make it unusable. A deployment outside them does not start.
 */
final class StepUpBoundsTest extends TestCase
{
    public function testTheShippedValuesAreWithinTheBounds(): void
    {
        $this->expectNotToPerformAssertions();

        (new StepUpBounds())->process($this->container('PT5M', 5));
    }

    /** @return iterable<string, array{string, int}> */
    public static function withinBounds(): iterable
    {
        yield 'the shortest window and the fewest attempts' => ['PT1M', 3];
        yield 'the longest window and the most attempts' => ['PT15M', 10];
        yield 'a window in seconds' => ['PT90S', 4];
    }

    #[DataProvider('withinBounds')]
    public function testValuesWithinTheBoundsAreKept(string $validFor, int $attempts): void
    {
        $this->expectNotToPerformAssertions();

        (new StepUpBounds())->process($this->container($validFor, $attempts));
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function outOfBounds(): iterable
    {
        yield 'a window under a minute' => ['PT59S', 5, 'app.step_up.valid_for'];
        yield 'a window over a quarter hour' => ['PT16M', 5, 'app.step_up.valid_for'];
        yield 'a window of a day' => ['P1D', 5, 'app.step_up.valid_for'];
        yield 'a window that is no duration' => ['five minutes', 5, 'app.step_up.valid_for'];
        yield 'two attempts' => ['PT5M', 2, 'app.step_up.attempts'];
        yield 'eleven attempts' => ['PT5M', 11, 'app.step_up.attempts'];
    }

    #[DataProvider('outOfBounds')]
    public function testAValueOutOfItsBoundsStopsTheContainer(string $validFor, int $attempts, string $named): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($named, '/').'/');

        (new StepUpBounds())->process($this->container($validFor, $attempts));
    }

    private function container(string $validFor, int $attempts): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('app.step_up.valid_for', $validFor);
        $container->setParameter('app.step_up.attempts', $attempts);

        return $container;
    }
}
