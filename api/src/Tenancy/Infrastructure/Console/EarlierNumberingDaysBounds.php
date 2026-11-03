<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Console;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The most hours an operator may let a company's series take a day before their last number's, after its computer's clock
 * ran ahead, is the operator's (app.numbering.earlier_days_hours_max: AllowEarlierNumberingDays), within bounds checked
 * when the container is built: at least an hour, the least the command itself takes, and at most the shipped three days,
 * which an operator may only shorten: how long a series may take a day before its last number's is a fiscal question
 * the screens guide but never answer, so no setting lengthens it.
 */
final class EarlierNumberingDaysBounds implements CompilerPassInterface
{
    public const string HOURS_MAX = 'app.numbering.earlier_days_hours_max';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::HOURS_MAX, 1, 72);
    }
}
