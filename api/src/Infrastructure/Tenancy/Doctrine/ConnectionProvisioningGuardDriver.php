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

/**
 * Verifies tenancy provisioning on each newly acquired connection.
 *
 * A named class rather than an anonymous one inside `wrap()`, for the reason
 * {@see SavepointTenantBindingDriver} gives: an anonymous class is unnameable in a stack trace, invisible to
 * `CoversClass`, and impossible to unit-test.
 *
 * `#[\SensitiveParameter]` carries through from the parent because `$params` holds the database password — and this is
 * the frame that appears in the trace when provisioning verification FAILS, i.e. exactly the trace that gets logged.
 *
 * It wraps NOTHING ELSE. Unlike {@see SavepointTenantBindingDriver} this driver returns the underlying connection
 * untouched, because the check belongs to acquisition alone: the properties asserted are catalogue state, and no
 * statement issued afterwards can change them.
 *
 * @see ConnectionProvisioningGuardMiddleware for the cadence ruling, the measurement behind it, and the stated gap
 */
final class ConnectionProvisioningGuardDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly ConnectionProvisioningGuardMiddleware $guard,
    ) {
        parent::__construct($driver);
    }

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[\SensitiveParameter]
        array $params,
    ): DriverConnection {
        $connection = parent::connect($params);

        $this->guard->verifyOnce($connection, $params);

        return $connection;
    }
}
