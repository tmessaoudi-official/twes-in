<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Session;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How closely a session's record knows its last use is the operator's (app.session.touch_every), within bounds checked
 * when the container is built: under a minute writes the record on nearly every request, over a quarter hour leaves
 * « Où vous êtes connecté » and the demo's sweep reading a use half an idle session old. Ending a session never depends
 * on it: the record is read on every request whatever this says.
 *
 * How long a session lasts is the operator's too, in seconds: app.session.idle_ttl, the row's expiry
 * (PostgresSessionHandler), and app.session.absolute_ttl, from its creation (SessionTimeoutListener). Their ceilings
 * are the shipped thirty minutes and twelve hours the product's authentication was ruled with, so an operator may only
 * shorten them: a longer session is one an unattended counter's screen keeps open to whoever passes. Under five minutes
 * idle a person writing an invoice is signed out mid-line, and the handler pushes the expiry forward only once a minute;
 * under an hour in all a working day is a string of sign-ins. The idle ceiling sits below the floor in all, so a session
 * is never idle longer than it may last.
 */
final class SessionBounds implements CompilerPassInterface
{
    public const string TOUCH_EVERY = 'app.session.touch_every';
    public const string IDLE_TTL = 'app.session.idle_ttl';
    public const string ABSOLUTE_TTL = 'app.session.absolute_ttl';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::TOUCH_EVERY, 60, 900, 'one minute to a quarter hour (PT1M to PT15M)');
        ParameterBounds::whole($container, self::IDLE_TTL, 300, 1_800);
        ParameterBounds::whole($container, self::ABSOLUTE_TTL, 3_600, 43_200);
    }
}
