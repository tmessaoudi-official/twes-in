<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Http;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How often a page support reads is written again to the owner's activity log is the operator's
 * (app.support.read_recorded_every), within bounds checked when the container is built: zero records every read, an
 * hour at most, so the owner always learns where support went. Support's entry is recorded whatever this says, and no
 * value switches the journal off.
 */
final class SupportVisitBounds implements CompilerPassInterface
{
    public const string READ_RECORDED_EVERY = 'app.support.read_recorded_every';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::READ_RECORDED_EVERY, 0, 3_600, 'zero, every read, to an hour (PT0M to PT1H)');
    }
}
