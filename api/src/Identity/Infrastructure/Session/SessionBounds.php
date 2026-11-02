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
 */
final class SessionBounds implements CompilerPassInterface
{
    public const string TOUCH_EVERY = 'app.session.touch_every';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::TOUCH_EVERY, 60, 900, 'one minute to a quarter hour (PT1M to PT15M)');
    }
}
