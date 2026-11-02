<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * How an operator's parameter is held between its floor and its ceiling when the container is built: a deployment
 * outside them never starts, so no value typed in services.yaml weakens what the shipped value protects. Each context's
 * compiler pass names its parameters and their bounds; this says how a duration, a whole number and a switch are read.
 */
final class ParameterBounds
{
    /**
     * @param string $said the bounds as a person reads them, « PT1M to PT15M »
     *
     * @throws InvalidArgumentException when the parameter is no ISO 8601 duration within the bounds
     */
    public static function duration(ContainerBuilder $container, string $parameter, int $shortestSeconds, int $longestSeconds, string $said): void
    {
        $value = $container->getParameter($parameter);
        $seconds = \is_string($value) ? self::seconds($value) : null;
        if (null === $seconds || $seconds < $shortestSeconds || $seconds > $longestSeconds) {
            throw new InvalidArgumentException(\sprintf('%s is an ISO 8601 duration from %s; %s is not.', $parameter, $said, json_encode($value)));
        }
    }

    /** @throws InvalidArgumentException when the parameter is no whole number within the bounds */
    public static function whole(ContainerBuilder $container, string $parameter, int $fewest, int $most): void
    {
        $value = $container->getParameter($parameter);
        if (!\is_int($value) || $value < $fewest || $value > $most) {
            throw new InvalidArgumentException(\sprintf('%s is a whole number from %d to %d; %s is not.', $parameter, $fewest, $most, json_encode($value)));
        }
    }

    /**
     * A switch is true or false itself: YAML reads « yes », « no », « on » and « off » as text, and a bool argument
     * would take any of them, « no » included, as true.
     *
     * @throws InvalidArgumentException when the parameter is no bool
     */
    public static function bool(ContainerBuilder $container, string $parameter): void
    {
        $value = $container->getParameter($parameter);
        if (!\is_bool($value)) {
            throw new InvalidArgumentException(\sprintf('%s is true or false; %s is not.', $parameter, json_encode($value)));
        }
    }

    /** The duration in seconds, counted from a fixed day so a month or a year has one length; null when it is none. */
    public static function seconds(string $duration): ?int
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
