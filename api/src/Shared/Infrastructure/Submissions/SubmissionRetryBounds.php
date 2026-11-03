<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Submissions;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * How long the web keeps a write's key while no answer came (app.submissions.retry_minutes) is the operator's, checked
 * when the container is built: at least a minute, and always shorter than the API keeps a key (app.submissions.kept_hours),
 * or a write sent again after the API forgot its key would be made twice.
 */
final class SubmissionRetryBounds implements CompilerPassInterface
{
    public const string RETRY_MINUTES = 'app.submissions.retry_minutes';
    public const string KEPT_HOURS = 'app.submissions.kept_hours';

    public function process(ContainerBuilder $container): void
    {
        // A whole number of hours first: the window below is measured against it.
        ParameterBounds::whole($container, self::KEPT_HOURS, 1, \PHP_INT_MAX);
        ParameterBounds::whole($container, self::RETRY_MINUTES, 1, $container->getParameter(self::KEPT_HOURS) * 60 - 1, 'less than '.self::KEPT_HOURS.' in minutes');
    }
}
