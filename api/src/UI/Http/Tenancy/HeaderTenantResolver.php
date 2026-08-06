<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\Tenancy;

use Symfony\Component\HttpFoundation\Request;
use Twes\Infrastructure\Tenancy\TenantId;
use Twes\Infrastructure\Tenancy\TenantResolver;

/**
 * A DEVELOPMENT-ONLY resolver that trusts an `X-Tenant-Id` header. **This is not authentication.**
 *
 * **READ THIS BEFORE ENABLING IT ANYWHERE.** The header is supplied by the caller and verified by nothing, so
 * whoever can reach the application can act as any tenant whose id they can produce — and a tenant id is a UUID
 * that appears in URLs, exports and support tickets. Enabling this on a reachable deployment is a cross-tenant
 * read of every client's invoices, which is the single worst defect this product can have and a reportable data
 * breach rather than a bug.
 *
 * **SO WHY DOES IT EXIST AT ALL?** Because Wave 1's HTTP surface has to be buildable and testable before Wave 7
 * writes authentication, and the alternative — no request-time tenancy until auth lands — would mean the
 * repository, the isolation strategy and the boundary rule all stay unexercised over HTTP until then. The
 * honest framing is that this is a **test double promoted to a service**, and it is treated with the suspicion
 * that deserves.
 *
 * **THREE INDEPENDENT THINGS HAVE TO GO WRONG for this to reach production, which is the design:**
 *
 * 1. It **refuses to resolve** unless `$permitted` is true, and refuses loudly — a `\RuntimeException` naming
 *    itself, not a quiet null. A quiet null would present as "you are nobody", which a caller reads as an empty
 *    account rather than as a rejected request.
 * 2. `$permitted` comes from `TWES_TRUST_TENANT_HEADER`, which is `0` in `api/.env` and `0` in `infra/.env`.
 *    **Both**, deliberately: the containerised stack is the one a developer is most likely to expose.
 * 3. **`scripts/gates/no-forgeable-tenancy-in-production.sh` refuses to let it be enabled in any tracked
 *    production configuration**, and that gate is the load-bearing one. Configuration that is merely *documented*
 *    as dev-only becomes production configuration the first time somebody copies a file — `CLAUDE.md` § Gotchas
 *    records four separate instances of a control that existed only in prose. The precedent is
 *    `worker-mode-blocked.sh`: a capability deliberately refused by a gate until the wave that makes it safe, and
 *    deleted with that wave rather than before it.
 *
 * **DELETE THIS CLASS IN WAVE 7**, along with the env variable and the gate. It is not a component with a future;
 * it is scaffolding with an expiry date, and leaving it behind an authenticated resolver would mean a second,
 * forgeable path to the same privilege.
 */
final readonly class HeaderTenantResolver implements TenantResolver
{
    /**
     * The header name, and it is `X-Tenant-Id` for a reason worth stating: it is **obviously** a caller-supplied
     * value. A friendlier name (`Tenant`, or worse a cookie) would read like something the framework had
     * established. The `X-` prefix is deprecated for standardised headers and exactly right for this one.
     */
    public const string HEADER = 'X-Tenant-Id';

    public function __construct(
        /**
         * Whether this resolver may act. False in every committed configuration; see the class docblock for the
         * three things that must go wrong for it to be true where it matters.
         */
        private bool $permitted,
    ) {}

    public function resolve(Request $request): ?TenantId
    {
        $claimed = $request->headers->get(self::HEADER);

        // NO HEADER IS NOT A REFUSAL. A liveness probe, the OpenAPI document and the currency registry are
        // genuinely tenant-less, and returning null for them is correct even when this resolver is forbidden —
        // which is why the permitted check is BELOW this line and not above it. Putting it above would make a
        // health check fail in production, and a health check that fails because of a tenancy setting is how the
        // setting gets turned on.
        if (null === $claimed || '' === $claimed) {
            return null;
        }

        if (!$this->permitted) {
            throw new \RuntimeException(\sprintf(
                'This request sent a %s header, and trusting it is NOT PERMITTED in this configuration. The '
                . 'header is verified by nothing, so honouring it would let any caller act as any tenant — a '
                . 'cross-tenant read of every client\'s invoices. It exists only so Wave 1\'s HTTP surface can '
                . 'be exercised before Wave 7 writes authentication, and it is enabled by '
                . 'TWES_TRUST_TENANT_HEADER, which is 0 in every committed configuration and which '
                . 'scripts/gates/no-forgeable-tenancy-in-production.sh refuses to see enabled in a production '
                . 'one. REFUSED rather than ignored: silently returning no tenant would present as an empty '
                . 'account instead of a rejected request.',
                self::HEADER,
            ));
        }

        // VALIDATED BY THE TYPE THAT OWNS THE RULE. `TenantId::fromString()` refuses anything that is not a
        // canonical UUID, and letting it do so means there is exactly one definition of a well-formed tenant id.
        // Re-wrapped because the caller needs a message about the REQUEST, not about a value object.
        try {
            return TenantId::fromString($claimed);
        } catch (\InvalidArgumentException $malformed) {
            throw new \RuntimeException(\sprintf(
                'The %s header carried "%s", which is not a canonical tenant id: %s. Refused rather than '
                . 'ignored — a malformed claim is a caller error, and treating it as "no tenant" would answer a '
                . 'broken request with an empty result set.',
                self::HEADER,
                $claimed,
                $malformed->getMessage(),
            ), 0, $malformed);
        }
    }
}
