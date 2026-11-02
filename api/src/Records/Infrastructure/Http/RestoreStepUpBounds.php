<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Records\Infrastructure\Http;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Whether restoring from « Récemment supprimés » asks a proof given again is the operator's (app.step_up.on_restore),
 * checked when the container is built: it may only add a proof, never take away the one a merge's undo asks, and it is
 * a switch written true or false, never a word YAML leaves as text.
 */
final class RestoreStepUpBounds implements CompilerPassInterface
{
    public const string ON_RESTORE = 'app.step_up.on_restore';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::bool($container, self::ON_RESTORE);
    }
}
