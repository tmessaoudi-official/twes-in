<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Infrastructure\Http;

use App\Identity\Infrastructure\Session\SessionBounds;
use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The demo's two durations are the operator's, within bounds checked when the container is built. How long a visitor may
 * step away (app.demo.idle_ttl, in seconds), which DemoEntryController gives the demo's session and RemoveSandboxes
 * waits before taking its sandbox away: at least the thirty minutes a real account may, and never past how long any
 * session lasts (app.session.absolute_ttl), which SessionTimeoutListener ends for a demo's as for anyone's, so a longer
 * stay would never happen while its sandbox waited for it. How long a half-built sandbox is waited for
 * (app.demo.build_gives_up_after, RemoveSandboxes): a build takes under a minute and the sweep runs every five, so under
 * ten minutes a slow build is taken away while it works, and past six hours a stuck one keeps its country's demo closed.
 */
final class DemoBounds implements CompilerPassInterface
{
    public const string IDLE_TTL = 'app.demo.idle_ttl';
    public const string BUILD_GIVES_UP_AFTER = 'app.demo.build_gives_up_after';

    public function process(ContainerBuilder $container): void
    {
        // A whole number first, rather than trusting the order passes run in: the ceiling below is measured against it.
        // Its own range is SessionBounds'.
        ParameterBounds::whole($container, SessionBounds::ABSOLUTE_TTL, 1, \PHP_INT_MAX);
        ParameterBounds::whole($container, self::IDLE_TTL, 1_800, $container->getParameter(SessionBounds::ABSOLUTE_TTL), 'at most '.SessionBounds::ABSOLUTE_TTL);
        ParameterBounds::duration($container, self::BUILD_GIVES_UP_AFTER, 600, 6 * 3_600, 'ten minutes to six hours (PT10M to PT6H)');
    }
}
