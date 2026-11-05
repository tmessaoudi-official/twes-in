<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\StepUp;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The step-up's two numbers are a deployment's to set (docs/SPEC.md § 7), within bounds checked when the container is
 * built, so a deployment outside them never starts: a proof held for a day, or a hundred guesses, would undo what the
 * list is for; a proof gone in seconds, or one typo ending the sign-in, would make it unusable. Which actions ask stays
 * code.
 */
final class StepUpBounds implements CompilerPassInterface
{
    public const string VALID_FOR = 'app.step_up.valid_for';
    public const string ATTEMPTS = 'app.step_up.attempts';
    private const int SHORTEST_SECONDS = 60;
    private const int LONGEST_SECONDS = 900;
    private const int FEWEST_ATTEMPTS = 3;
    private const int MOST_ATTEMPTS = 10;

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::VALID_FOR, self::SHORTEST_SECONDS, self::LONGEST_SECONDS, 'one minute to a quarter hour (PT1M to PT15M)');
        // A whole number itself: YAML reads a quoted 5 as text, which compared as a number and passed.
        ParameterBounds::whole($container, self::ATTEMPTS, self::FEWEST_ATTEMPTS, self::MOST_ATTEMPTS);
    }
}
