<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\DependencyInjection\ParameterBounds;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The seconds a request whose work grows with what a person handed in is given is the operator's
 * (app.long_request.max_seconds: LongRequest), within bounds checked when the container is built. It is also how old a
 * write's claim must be before SubmissionGuard takes it back, so a value shorter than the longest import lets a retry make
 * a running import a second time: a minute, nginx's own default wait, is the floor, an import of 4,100 products having
 * taken 50 s on a developer's machine. Past a quarter hour a lost write's key stays claimed that long, and a worker thread
 * is held by one request. infra/web/nginx.conf waits exactly as long, which LongRequestRoutesTest holds.
 */
final class LongRequestBounds implements CompilerPassInterface
{
    public const string MAX_SECONDS = 'app.long_request.max_seconds';

    public function process(ContainerBuilder $container): void
    {
        ParameterBounds::whole($container, self::MAX_SECONDS, 60, 900);
    }
}
