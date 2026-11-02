<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\ErrorReporting;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * What a browser's report may hold, and how many one page load sends, are the operator's (app.client_errors.*), checked
 * when the container is built: no body so small a stack never fits nor so large one page fills the log, no message or
 * stack cut to nothing or kept whole, no page reporting without end. 0 reports is allowed: the page then sends none.
 */
final class ClientErrorBounds implements CompilerPassInterface
{
    public const string MAX_BODY = 'app.client_errors.max_body';
    public const string MAX_MESSAGE = 'app.client_errors.max_message';
    public const string MAX_STACK = 'app.client_errors.max_stack';
    public const string PER_PAGE = 'app.client_errors.per_page';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::MAX_BODY, 1_024, 65_536);
        ParameterBounds::whole($container, self::MAX_MESSAGE, 100, 2_000);
        ParameterBounds::whole($container, self::MAX_STACK, 500, 16_000);
        ParameterBounds::whole($container, self::PER_PAGE, 0, 20);
    }
}
