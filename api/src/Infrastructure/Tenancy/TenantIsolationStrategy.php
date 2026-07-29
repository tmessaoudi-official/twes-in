<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Tenancy;

/**
 * *How* one tenant's data is kept away from another's.
 *
 * Two modes are planned, chosen by configuration (`TWES_TENANCY_MODE`), and this interface is the seam
 * that makes the choice invisible to every caller:
 *
 *   - **shared** — one database for everyone, every tenant-owned row carrying its tenant, and the
 *     database itself refusing to return another tenant's rows. Implemented:
 *     {@see PostgresRowLevelSecurityIsolation}.
 *   - **database** — one database per tenant, isolation by virtue of the connection. Not yet
 *     implemented; the seam exists so that adding it is an adapter rather than a rewrite. What it will
 *     cost is recorded in docs/plans/reimplementation-strategy.plan.md: migrations run per tenant,
 *     provisioning becomes a workflow, and cross-tenant reporting needs fan-out.
 *
 * The parameter is a PDO connection because Wave 0 has no ORM yet. When Doctrine lands this becomes
 * its `Connection`; both are Infrastructure types, so that change crosses no layer boundary.
 */
interface TenantIsolationStrategy
{
    /**
     * Bind a database session to the current tenant. Must run before any query on that session.
     *
     * Implementations are expected to **fail closed**: if isolation cannot be established, no data may
     * be readable. Returning quietly and leaving a session unscoped is the failure mode this whole
     * interface exists to prevent.
     */
    public function bind(\PDO $connection, TenantContext $context): void;
}
