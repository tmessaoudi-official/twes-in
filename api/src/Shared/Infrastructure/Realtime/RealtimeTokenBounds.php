<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Realtime;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long a realtime connection token lives is the operator's (app.realtime.token_lifetime, in seconds: CentrifugoTokens),
 * within bounds checked when the container is built. Centrifugo has no way to revoke a token, so its lifetime is how long
 * a connection that escaped its session's end still hears its company's change signals: past five minutes that window
 * grows back towards the quarter hour a review found too long. Under a minute the browser asks for a new token nearly
 * all the time, and a server clock a few seconds off the API's turns every new token into an expired one.
 */
final class RealtimeTokenBounds implements CompilerPassInterface
{
    public const string TOKEN_LIFETIME = 'app.realtime.token_lifetime';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::TOKEN_LIFETIME, 60, 300);
    }
}
