<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Signup;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long a signup link works is the operator's (app.signup.valid_for), within bounds checked when the container is
 * built: the mail says it in whole hours, so a link of less than one would say « 0 heures » and be gone before a slow
 * mail arrives, and one kept past a week opens an account to whoever finds the mail later, when an invitation's
 * longest is a month for a company that already exists.
 */
final class SignupBounds implements CompilerPassInterface
{
    public const string VALID_FOR = 'app.signup.valid_for';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::duration($container, self::VALID_FOR, 3_600, 7 * 86_400, 'an hour to a week (PT1H to P7D)');
    }
}
