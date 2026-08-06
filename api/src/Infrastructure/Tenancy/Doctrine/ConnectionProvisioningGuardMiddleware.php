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
use Doctrine\DBAL\Driver\Middleware;
use Psr\Cache\CacheItemPoolInterface;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;

/**
 * Assert on ACQUISITION that this connection's role cannot step around row-level security.
 *
 * Obligations 2, 3 and 4 of `build-waves.plan.md`'s connection-lifecycle list, wired at the seam that acquires a
 * connection — a DBAL driver middleware's `connect()`, which is the same seam
 * {@see SavepointTenantBindingMiddleware} uses and for the same reason: the call happens without any caller
 * remembering to make it.
 *
 * **ONCE PER (ROLE, DATABASE) PER TTL WINDOW, NOT PER ACQUISITION — a developer ruling of 2026-08-06 taken against a
 * measurement, and the measurement is why it was a decision rather than an optimisation.** The composite check is
 * **7.33 ms**, `assertConnectionCannotCreateLargeObjects()` **3.14 ms** and
 * `assertConnectionCannotCreateTemporaryObjects()` **0.36 ms** (50 runs each, migrated database) — ~10.8 ms, which
 * under PHP-FPM would be paid on every request, roughly a fifth of a 50 ms API response.
 *
 * The observation that makes caching CORRECT rather than merely cheaper: **every property checked is STATIC per
 * (role, database)** — `rolsuper`, `rolbypassrls`, `rolreplication`, table ownership, `TRUNCATE`, and function ACLs
 * are catalogue state that no request can alter. Re-verifying them per acquisition re-verifies a constant. The
 * genuinely per-session property — a connection arriving already bound to a tenant — is NOT checkable here at all: a
 * brand-new backend cannot carry a `twes.tenant_id`, so that hazard belongs to REUSE and is
 * {@see SessionStateReleaser}'s job.
 *
 * **THE GAP THIS LEAVES, stated rather than left to be discovered:** provisioning that drifts *during* a process's
 * lifetime is not caught until the cached verification expires — a window bounded by `$verificationTtl` and stated in
 * `services.yaml` rather than left implicit. Two things make that acceptable and neither is "it is unlikely". First, `scripts/gates/schema-tenancy.php`
 * asserts the same role attributes, ownership and `TRUNCATE` against a live schema and FAILS rather than skipping, so
 * the properties are checked at deploy time by something that reads the database rather than trusting a cache.
 * Second, an attacker who can `ALTER ROLE` can also `DROP POLICY`, which no per-acquisition check would survive
 * either — so the per-request cadence was never buying protection against that adversary.
 *
 * **Items 3 and 4 are PRODUCTION-ONLY, and that is not an exemption.** The provisioned TEST database deliberately
 * grants `TEMPORARY` (the column-fidelity suite needs a scratch table) and has not run the large-object
 * `REVOKE EXECUTE` statements, so composing them into the acquisition check would fail every test run — which is
 * exactly why `PostgresRowLevelSecurityIsolation` leaves them out of its own composite. They are also the two that
 * CANNOT move into `composer gate` for the identical reason, so this is the only place they can run at all.
 */
final class ConnectionProvisioningGuardMiddleware implements Middleware
{
    /**
     * (role, database) pairs already verified in THIS KERNEL, in front of the cache pool.
     *
     * Two layers, because they answer different questions. This one avoids a cache round trip when one kernel opens
     * the same connection twice; the POOL is what carries the result across requests.
     *
     * INSTANCE state, not `static`: this project has two connections whose whole point is that they are DIFFERENT
     * ROLES with different privileges (`doctrine.yaml` calls the split a security boundary), and a `static` would be
     * shared by both. Keying by the pair is belt and braces on top of that.
     *
     * @var array<string, true>
     */
    private array $verifiedInThisKernel = [];

    public function __construct(
        private readonly PostgresRowLevelSecurityIsolation $isolation,
        /**
         * WHY A CACHE POOL AND NOT AN IN-PROCESS FLAG, which is where the first draft of this class was wrong.
         *
         * PHP is shared-nothing: under PHP-FPM every request gets a fresh execution context, so a `static` does not
         * survive between requests and a fresh kernel rebuilds this service anyway. An in-process cache therefore
         * amortises across nothing under HTTP — it would have paid the full ~10.8 ms on every single request while
         * LOOKING like it had been optimised, which is worse than not caching at all because the code would claim a
         * property it does not have.
         *
         * A cache pool is the only thing in the ecosystem that crosses a request boundary. `cache.app` is APCu or the
         * filesystem, both of which read in well under a millisecond against 10.8 ms of catalogue queries.
         */
        private readonly CacheItemPoolInterface $verifiedProvisioning,
        /**
         * How long a verification stays trusted, in seconds. This is the STALENESS WINDOW, and it is a parameter
         * rather than a constant because it is the one number a deployment might reasonably argue about: it bounds how
         * long a provisioning change (`ALTER ROLE twes BYPASSRLS`) can go unnoticed by the runtime check.
         */
        private readonly int $verificationTtl,
        /**
         * Whether the production-only capability checks run. `%kernel.environment%` is deliberately NOT the
         * discriminator: the real question is "has this database had its capabilities revoked", and the environment
         * name is a proxy for it that would silently become wrong the day a staging database is provisioned like
         * production. An explicit flag makes the coupling visible in `services.yaml`.
         */
        private readonly bool $assertRevokedCapabilities,
    ) {}

    public function wrap(Driver $driver): Driver
    {
        // A NAMED class, not an anonymous one, for the reason `SavepointTenantBindingDriver` already states: an
        // anonymous class is unnameable in a stack trace, invisible to `CoversClass`, and impossible to unit-test.
        // The first draft of this method used one and php-cs-fixer's spacing rule is what made me re-read it —
        // worth recording, because writing the rule down in a sibling file an hour earlier had not stopped me.
        return new ConnectionProvisioningGuardDriver($driver, $this);
    }

    /**
     * The cache key for one connection's (role, host, port, database).
     *
     * **PUBLIC so the test uses this function rather than a copy of it.** A test that reimplemented the formula would
     * pass while agreeing with itself about the wrong key — the shape § Gotchas 2026-07-31 records as a P0, where a
     * validator derived its expected value from the input it was validating.
     *
     * IT EXCLUDES THE PASSWORD, deliberately: the password is not part of what is being asserted, and a credential in
     * a cache key is a credential in a `var_dump`, a cache-inspection command and a filesystem path.
     *
     * `sha1` because a PSR-6 key forbids `{}()/\@:` and a DSN contains several of them. Not for secrecy — there is no
     * secret in the input — so a fast digest is the right tool, and the reason is written down so nobody "upgrades" it
     * to something slower on a hot path.
     *
     * @param array<string, mixed> $params
     */
    public static function cacheKeyFor(array $params): string
    {
        return 'twes.tenancy.provisioning.' . sha1(\sprintf(
            '%s@%s:%s/%s',
            \is_scalar($params['user'] ?? null) ? (string) $params['user'] : '?',
            \is_scalar($params['host'] ?? null) ? (string) $params['host'] : '?',
            \is_scalar($params['port'] ?? null) ? (string) $params['port'] : '?',
            \is_scalar($params['dbname'] ?? null) ? (string) $params['dbname'] : '?',
        ));
    }

    /**
     * Run the provisioning assertions unless this (role, database) has already been verified.
     *
     * @param array<string, mixed> $params
     *
     * @throws \RuntimeException if the role can step around row-level security
     * @throws \LogicException if the driver's native connection is not a `\PDO`
     */
    public function verifyOnce(DriverConnection $connection, array $params): void
    {
        $key = self::cacheKeyFor($params);

        if (isset($this->verifiedInThisKernel[$key])) {
            return;
        }

        $item = $this->verifiedProvisioning->getItem($key);

        if ($item->isHit()) {
            $this->verifiedInThisKernel[$key] = true;

            return;
        }

        $native = $connection->getNativeConnection();

        if (!$native instanceof \PDO) {
            throw new \LogicException(\sprintf(
                'Cannot verify tenancy provisioning: the driver\'s native connection is %s, not \\PDO. This is what '
                . 'stops the application running as a role that can step around row-level security, so it must not '
                . 'degrade to a no-op. Use the `pdo_pgsql` driver, which is the only one twes-in supports.',
                get_debug_type($native),
            ));
        }

        // MARKED IN-KERNEL BEFORE THE CHECK, and the reason is re-entrancy rather than optimism: the assertions issue
        // queries on this same connection, and any of them reaching `connect()` again would recurse forever.
        //
        // THE POOL IS **NOT** WRITTEN YET, and that asymmetry is the whole point: a failed verification must not be
        // remembered as a success, and it must not be remembered as a failure either — the next request has to re-ask,
        // because the fix for a wrongly-provisioned database is to fix the database, and a cached failure would keep
        // rejecting a database that had just been repaired.
        $this->verifiedInThisKernel[$key] = true;

        $this->isolation->assertConnectionCannotBypassPolicies($native);

        if ($this->assertRevokedCapabilities) {
            // NOT in the composite above, deliberately — see the class docblock. These two assert that a REVOCATION
            // actually happened, which is a property of a production-provisioned database only.
            PostgresRowLevelSecurityIsolation::assertConnectionCannotCreateTemporaryObjects($native);
            PostgresRowLevelSecurityIsolation::assertConnectionCannotCreateLargeObjects($native);
        }

        // ONLY NOW. Reaching this line means every applicable assertion passed.
        $item->set(true)->expiresAfter($this->verificationTtl);
        $this->verifiedProvisioning->save($item);
    }

}
