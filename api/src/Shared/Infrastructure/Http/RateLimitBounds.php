<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Every rate limiter's budget is the operator's (app.rate_limit.<name>.limit and .interval, which
 * config/packages/rate_limiter.yaml reads), within bounds checked when the container is built: no try at all shuts the
 * door it guards on everyone, a hundred thousand is the limiter switched off; a budget counted over seconds refills
 * before a guesser slows, one counted over more than a day keeps the person it locked out waiting past the next working
 * day. Each limiter is found by its name, so one added later is held the day it comes.
 */
final class RateLimitBounds implements CompilerPassInterface
{
    private const string PREFIX = 'app.rate_limit.';
    private const int FEWEST = 1;
    private const int MOST = 1_000;

    public function process(ContainerBuilder $container): void
    {
        foreach (array_keys($container->getParameterBag()->all()) as $name) {
            if (!str_starts_with($name, self::PREFIX)) {
                continue;
            }
            if (str_ends_with($name, '.limit')) {
                ParameterBounds::whole($container, $name, self::FEWEST, self::MOST);
            } elseif (str_ends_with($name, '.interval')) {
                ParameterBounds::spoken($container, $name, 60, 86_400, 'one minute to a day');
            }
        }
    }
}
