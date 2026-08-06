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

use Symfony\Component\HttpFoundation\Request;

/**
 * *How* an inbound HTTP request is identified as belonging to a tenant.
 *
 * The third piece of the tenancy seam. {@see TenantContext} answers *who* the current tenant is and
 * {@see TenantIsolationStrategy} answers *how* their data is kept apart; this answers *how the request said so*,
 * and it is deliberately the only one of the three that is allowed to be wrong about it.
 *
 * **WHY A PORT RATHER THAN A FUNCTION.** Wave 7 replaces the implementation entirely: a tenant will come from an
 * authenticated token, and the identification will be a *verified claim* rather than a value read off the wire.
 * The point of declaring the interface now is that the replacement is an adapter swap — nothing that consumes a
 * tenant learns where it came from, which is the same reason `TenantIsolationStrategy` exists.
 *
 * **RETURNING A TENANT IS AN ASSERTION THAT THE REQUEST PROVED IT.** An implementation that returns a
 * `TenantId` is stating that this request is entitled to act as that tenant. Under the Wave 7 adapter that
 * statement is backed by a signature. Under {@see \Twes\UI\Http\Tenancy\HeaderTenantResolver} it is backed by
 * nothing at all, which is why that class refuses to run unless explicitly permitted and why
 * `scripts/gates/no-forgeable-tenancy-in-production.sh` refuses to let it be permitted in a production
 * configuration. An implementation of this interface is a security boundary; treat adding one as such.
 *
 * NULL IS A LEGITIMATE ANSWER and does not mean failure. A liveness probe, the OpenAPI document, the API
 * entrypoint and the currency registry are all genuinely tenant-less — see {@see TenantContext::hasTenant()},
 * whose docblock names the same class of work. What must NOT happen is a null that *should* have been a tenant;
 * that is the caller's problem to detect, and the repository already refuses to hydrate an aggregate without one.
 */
interface TenantResolver
{
    /**
     * The tenant this request is entitled to act as, or null when it acts as none.
     *
     * @throws \RuntimeException if the request CLAIMS a tenant the implementation cannot honour — a malformed
     *                           identifier, or a claim this adapter is not permitted to accept. A refusal must be
     *                           loud: silently returning null would turn "you may not do that" into
     *                           "you are nobody", and the second reads to a caller as an empty account rather
     *                           than as a rejected request.
     */
    public function resolve(Request $request): ?TenantId;
}
