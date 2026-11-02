<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long a reset link works is the operator's (app.password_reset.valid_for), within bounds checked when the container
 * is built: a link that outlives a day is one more day for whoever reads that inbox to take the account, and one gone in
 * minutes expires before a slow mail arrives.
 */
final class PasswordResetBounds implements CompilerPassInterface
{
    public const string VALID_FOR = 'app.password_reset.valid_for';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::VALID_FOR, 900, 86_400, 'a quarter hour to a day (PT15M to PT24H)');
    }
}
