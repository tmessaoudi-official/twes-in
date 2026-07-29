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
 * A tenant context set directly.
 *
 * Used by CLI commands, tests and background workers, all of which are told which tenant to act for
 * rather than deriving it from a request. The HTTP adapter that resolves a tenant from a token lands
 * with authentication.
 *
 * Not readonly: the whole point is that it can be switched, which a worker consuming a queue of jobs
 * for different tenants must do.
 */
final class InMemoryTenantContext implements TenantContext
{
    private ?TenantId $tenantId = null;

    public static function forTenant(TenantId $tenantId): self
    {
        $context = new self();
        $context->switchTo($tenantId);

        return $context;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function switchTo(TenantId $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    /**
     * Drop the current tenant.
     *
     * Worth having explicitly: a worker that finishes a job should clear the tenant rather than leave
     * the previous one bound, so that a job which forgets to set one fails instead of silently
     * inheriting its predecessor's data.
     */
    public function clear(): void
    {
        $this->tenantId = null;
    }

    public function hasTenant(): bool
    {
        return null !== $this->tenantId;
    }

    public function tenantId(): TenantId
    {
        return $this->tenantId ?? throw NoCurrentTenant::create();
    }
}
