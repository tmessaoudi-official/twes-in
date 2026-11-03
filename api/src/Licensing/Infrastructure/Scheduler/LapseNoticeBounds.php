<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\Scheduler;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How many days before the time a company paid for ends its owners are told is the operator's
 * (app.subscription.lapse_notice_days: TellLapsingSubscriptions), within bounds checked when the container is built: none
 * tells them once access has already narrowed, past thirty days a monthly subscription is announced as lapsing the day
 * it is renewed.
 */
final class LapseNoticeBounds implements CompilerPassInterface
{
    public const string NOTICE_DAYS = 'app.subscription.lapse_notice_days';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::NOTICE_DAYS, 1, 30);
    }
}
