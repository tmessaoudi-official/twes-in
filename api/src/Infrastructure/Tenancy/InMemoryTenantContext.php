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

use Symfony\Contracts\Service\ResetInterface;
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
 *
 * **IT IS `ResetInterface`, AND THAT IS A TENANCY CONTROL RATHER THAN HOUSEKEEPING.** Symfony calls `reset()` on every
 * service carrying this interface between units of work — between Messenger messages, and between requests in a
 * resident runtime. `SessionStateReleaser` already does the DATABASE half for exactly that reason, and its docblock
 * names the hazard: *"a worker consuming a queue of jobs for DIFFERENT TENANTS on ONE connection … and `compose.yaml`
 * runs one"*. Without this half, the APPLICATION-side tenant survives the message boundary, so
 * `TenantBindingConnection::beginTransaction()` binds message #1's tenant onto message #2's transaction — and both
 * messages then read and write tenant A.
 *
 * **That direction is fail-OPEN, which is why it is worth two lines now rather than after the first handler.** Every
 * other tenancy failure in this project fails closed: an unbound session sees nothing, a wrong binding is refused. A
 * STALE binding is the one shape that succeeds while being wrong, and nothing downstream can tell it from a correct
 * one. Round 2's security lens found the gap and correctly called it latent — `grep -rn "AsMessageHandler" src/`
 * returns nothing today and `RequestTenantBinder` is bound to `kernel.request` only, so no code path currently carries
 * a tenant across a message. It lands before the first one does, not after.
 */
final class InMemoryTenantContext implements TenantContext, ResetInterface
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

    /**
     * Symfony's between-units-of-work hook, and deliberately nothing but a `clear()`.
     *
     * It is `clear()` rather than a reset of its own so there is ONE definition of "no tenant bound" — the shape
     * `CLAUDE.md` § Gotchas records as the fix for a condition implemented on one path and not another. Reset must
     * leave the context in the state a new one is in, and `clear()` is that state.
     *
     * Placed AFTER `clear()` rather than before it, because inserting it above stranded `clear()`'s own docblock
     * between two doc comments — `scripts/gates/no-orphaned-docblocks.php` caught that on the first gate run.
     */
    public function reset(): void
    {
        $this->clear();
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
