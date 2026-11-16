<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use App\Tenancy\Domain\Model\SupportGrant;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * The windows an owner may open the company to support for (app.support.windows_hours) and the one offered first
 * (app.support.default_window_hours), checked when the container is built. Support stays time-boxed: the ruled windows
 * are the ceiling, so each is a whole number of hours from one to the longest of SupportGrant::HOURS, given once and in
 * order, and the first one offered is one of them.
 */
final class SupportWindowBounds implements CompilerPassInterface
{
    public const string WINDOWS = 'app.support.windows_hours';
    public const string DEFAULT_WINDOW = 'app.support.default_window_hours';

    public function process(ContainerBuilder $container): void
    {
        $longest = max(SupportGrant::HOURS);
        $windows = self::listOf($container->getParameter(self::WINDOWS));
        $previous = 0;
        foreach ($windows ?? [] as $hours) {
            if (!\is_int($hours) || $hours <= $previous || $hours > $longest) {
                $windows = null;
                break;
            }
            $previous = $hours;
        }
        if (null === $windows || [] === $windows) {
            throw new InvalidArgumentException(\sprintf('%s is a list of whole hours from 1 to %d, each once, shortest first; %s is not.', self::WINDOWS, $longest, json_encode($container->getParameter(self::WINDOWS))));
        }
        ParameterBounds::whole($container, self::DEFAULT_WINDOW, 1, $longest);
        if (!\in_array($container->getParameter(self::DEFAULT_WINDOW), $windows, true)) {
            throw new InvalidArgumentException(\sprintf('%s is one of %s, the windows offered.', self::DEFAULT_WINDOW, self::WINDOWS));
        }
    }

    /**
     * Read as what services.yaml may hold, whatever the compiled container's type says: an operator can write text there.
     *
     * @return list<mixed>|null null for what is no list
     */
    private static function listOf(mixed $value): ?array
    {
        return \is_array($value) && array_is_list($value) ? $value : null;
    }
}
