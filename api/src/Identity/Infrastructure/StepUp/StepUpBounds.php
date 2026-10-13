<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\StepUp;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

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
        $validFor = $container->getParameter(self::VALID_FOR);
        $seconds = self::seconds($validFor);
        if (null === $seconds || $seconds < self::SHORTEST_SECONDS || $seconds > self::LONGEST_SECONDS) {
            throw new InvalidArgumentException(\sprintf('%s is an ISO 8601 duration from one minute to a quarter hour (PT1M to PT15M); %s is not.', self::VALID_FOR, json_encode($validFor)));
        }

        $attempts = $container->getParameter(self::ATTEMPTS);
        if ($attempts < self::FEWEST_ATTEMPTS || $attempts > self::MOST_ATTEMPTS) {
            throw new InvalidArgumentException(\sprintf('%s is a whole number from %d to %d; %s is not.', self::ATTEMPTS, self::FEWEST_ATTEMPTS, self::MOST_ATTEMPTS, json_encode($attempts)));
        }
    }

    /** The duration in seconds, counted from a fixed day so a month or a year has one length; null when it is none. */
    private static function seconds(string $duration): ?int
    {
        try {
            $interval = new \DateInterval($duration);
        } catch (\Exception) {
            return null;
        }
        $from = new \DateTimeImmutable('2000-01-01T00:00:00Z');

        return $from->add($interval)->getTimestamp() - $from->getTimestamp();
    }
}
