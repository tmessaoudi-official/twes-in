<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

use App\Identity\Application\Password\NewPasswordPolicy;
use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The fewest characters a new password needs is the operator's (app.password.min_length), checked when the container is
 * built: never under the floor of twelve the requests themselves hold, so the parameter only raises it, and never over
 * sixty-four, past which a person asked to remember it writes it down instead (NIST SP 800-63B asks a verifier to take
 * at least 64 characters, so a 64 minimum is the longest every installation still accepts as typed).
 */
final class PasswordLengthBounds implements CompilerPassInterface
{
    public const string MIN_LENGTH = 'app.password.min_length';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::MIN_LENGTH, NewPasswordPolicy::MIN_LENGTH, 64, 'raise only: the floor is twelve');
    }
}
