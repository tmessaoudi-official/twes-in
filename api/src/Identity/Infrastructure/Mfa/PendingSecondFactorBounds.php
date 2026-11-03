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
 * How long a sign-in waits between the password and the second factor, and a passkey's options wait for their answer,
 * is the operator's (app.mfa.pending_ttl: PendingSecondFactor, PasskeyChallenges), within bounds checked when the
 * container is built, the same as a step-up proof's: under a minute the person fetching their phone or their key starts
 * again, past a quarter hour a password typed on a shared computer and left there opens the second step to whoever sits
 * down next.
 */
final class PendingSecondFactorBounds implements CompilerPassInterface
{
    public const string PENDING_TTL = 'app.mfa.pending_ttl';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::PENDING_TTL, 60, 900, 'one minute to a quarter hour (PT1M to PT15M)');
    }
}
