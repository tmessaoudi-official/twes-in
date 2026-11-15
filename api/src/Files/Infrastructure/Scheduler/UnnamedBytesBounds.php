<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Files\Infrastructure\Scheduler;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How old bytes no file row names must be before they are removed is the operator's (app.files.unnamed_removed_after),
 * within bounds checked when the container is built: under six hours a long worker job could still be on its way from
 * its bytes to its row, and past thirty days a disk that filled once stays full of what nobody can open.
 */
final class UnnamedBytesBounds implements CompilerPassInterface
{
    public const string REMOVED_AFTER = 'app.files.unnamed_removed_after';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::REMOVED_AFTER, 6 * 3_600, 30 * 86_400, 'six hours to thirty days (PT6H to P30D)');
    }
}
