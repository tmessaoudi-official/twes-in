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

use Twes\Infrastructure\Tenancy\Exception\NoCurrentTenant;

/**
 * *Who* the current tenant is — resolved once per request, from a token, a subdomain or a CLI option.
 *
 * One half of the tenancy seam. The other half, {@see TenantIsolationStrategy}, decides *how* that
 * tenant's data is kept separate. Splitting the two is what lets the isolation model change without a
 * single call site changing: everything that needs to know the tenant asks this, and nothing asks how
 * isolation happens.
 */
interface TenantContext
{
    /**
     * Whether a tenant is bound.
     *
     * False during genuinely tenant-less work — installation, a global health check, a cross-tenant
     * migration run. Callers must check rather than catch.
     */
    public function hasTenant(): bool;

    /**
     * @throws NoCurrentTenant if no tenant is bound
     */
    public function tenantId(): TenantId;
}
