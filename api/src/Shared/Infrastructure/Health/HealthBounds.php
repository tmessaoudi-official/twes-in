<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Health;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long a message due for the worker may wait before /api/health/deep fails is the operator's
 * (app.health.oldest_waiting_seconds: DeepHealth), within bounds checked when the container is built: under a minute the
 * monitor alerts on a worker busy with one message, past an hour a stopped worker (no reminder, no mail, no sandbox
 * swept) goes unseen for that long.
 */
final class HealthBounds implements CompilerPassInterface
{
    public const string OLDEST_WAITING_SECONDS = 'app.health.oldest_waiting_seconds';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::OLDEST_WAITING_SECONDS, 60, 3_600);
    }
}
