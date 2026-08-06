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
 * Wraps each new driver connection in {@see SavepointTenantBindingConnection}.
 *
 * A named class rather than an anonymous one inside `wrap()`: an anonymous class here would be invisible to
 * PHPStan's `CoversClass` bookkeeping, unnameable in a stack trace, and impossible to unit-test — and this project
 * prefers explicit wiring for exactly those reasons.
 *
 * `#[\SensitiveParameter]` is carried through from the parent's signature deliberately. `$params` holds the
 * database password, and dropping the attribute would put credentials into any stack trace this frame appears in —
 * which, for a class whose whole job is to throw on a tenancy failure, is precisely the frame that gets logged.
 *
 * @see SavepointTenantBindingMiddleware for why this seam is the right one, and what it deliberately ignores
 */
final class SavepointTenantBindingDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly TenantIsolationStrategy $isolation,
        private readonly TenantContext $context,
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
        return new SavepointTenantBindingConnection(
            parent::connect($params),
            $this->isolation,
            $this->context,
        );
    }
}
