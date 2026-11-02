<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Mfa;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How many recovery codes a set holds is the operator's (app.recovery_codes.per_set), within bounds checked when the
 * container is built: fewer than eight leaves a person who used a few with no way back in, more than sixteen is a sheet
 * nobody keeps. Each code's shape, alphabet and hashing stay code, so no value here lowers what one code resists.
 */
final class RecoveryCodeBounds implements CompilerPassInterface
{
    public const string PER_SET = 'app.recovery_codes.per_set';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::PER_SET, 8, 16);
    }
}
