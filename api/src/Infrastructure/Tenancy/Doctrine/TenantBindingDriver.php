<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Tenancy\Doctrine;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Twes\Infrastructure\Tenancy\TenantContext;
use Twes\Infrastructure\Tenancy\TenantIsolationStrategy;

/**
 * Wraps every connection this driver hands out in a {@see TenantBindingConnection}.
 *
 * Nothing more than that, and it is a separate class from the middleware for the reason
 * `SavepointTenantBindingDriver` gives: DBAL's `AbstractDriverMiddleware` is what the container tags, and the wrapping
 * happens in `connect()`, which only the driver sees.
 *
 * **This class must never be a service**, and `config/services.yaml` excludes it explicitly. It is constructed by
 * {@see TenantBindingMiddleware} around a `Driver` that DBAL supplies, so the container cannot autowire it — and
 * before the sibling exclusions existed the container listed all three savepoint wrappers purely because they sit
 * under an autowired namespace.
 */
final class TenantBindingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly TenantIsolationStrategy $isolation,
        private readonly TenantContext $context,
    ) {
        parent::__construct($driver);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        return new TenantBindingConnection(parent::connect($params), $this->isolation, $this->context);
    }
}
