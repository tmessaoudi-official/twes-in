<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Scheduler;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How many days before a closed company is removed with everything it holds its owners are told is the operator's
 * (app.company.removal_notice_days: TellClosedCompaniesNearRemoval), within bounds checked when the container is built:
 * under a week an owner away on holiday learns it too late to take the accounting export, past ninety days the mail is
 * forgotten by the night it speaks of.
 */
final class RemovalNoticeBounds implements CompilerPassInterface
{
    public const string NOTICE_DAYS = 'app.company.removal_notice_days';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::NOTICE_DAYS, 7, 90);
    }
}
