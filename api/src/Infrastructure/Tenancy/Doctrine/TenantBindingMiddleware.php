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
use Doctrine\DBAL\Driver\Middleware;
use Twes\Infrastructure\Tenancy\TenantContext;
use Twes\Infrastructure\Tenancy\TenantIsolationStrategy;

/**
 * The DBAL middleware that installs tenant binding on the RUNTIME connection.
 *
 * See {@see TenantBindingConnection} for what it does and why `beginTransaction()` is the only seam that can do it.
 * This class exists to be tagged.
 *
 * **SCOPED TO THE `default` CONNECTION, and the scoping is load-bearing.** `owner` exists so migrations have a
 * credential that is not the application's; a migration is legitimately tenant-less and runs before any tenant exists,
 * so binding there would guard nothing and could only fail. `config/services.yaml` therefore tags this with
 * `connection: default` — DoctrineBundle registers an autoconfiguration for `Doctrine\DBAL\Driver\Middleware` that
 * tags every implementation with a BARE `doctrine.middleware`, which really does mean every connection, so with no
 * explicit tag this lands on `owner` too.
 *
 * The neighbouring `autoconfigure: false` is NOT part of that, and this docblock claimed it was: a bare tag is
 * skipped outright when a scoped tag exists, so the two do not compete. The measurement and the correction are in
 * `config/services.yaml`; {@see \Twes\Tests\Functional\Tenancy\TenantBindingWiringTest} is what now holds the scoping
 * to account rather than a comment.
 */
final readonly class TenantBindingMiddleware implements Middleware
{
    public function __construct(
        private TenantIsolationStrategy $isolation,
        private TenantContext $context,
    ) {}

    public function wrap(Driver $driver): Driver
    {
        // A NAMED CLASS, not an anonymous one. `SavepointTenantBindingDriver`'s own reasoning applies: an anonymous
        // class here cannot be excluded from autowiring by path, cannot be named in a stack trace, and cannot be
        // referred to by the test that proves this middleware is installed.
        return new TenantBindingDriver($driver, $this->isolation, $this->context);
    }
}
