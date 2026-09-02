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
use Symfony\Contracts\Service\ResetInterface;
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
 * **MOST of what is checked is STATIC per (role, database)** — `rolsuper`, `rolbypassrls`, `rolreplication`, table
 * ownership, `TRUNCATE`, and function ACLs are catalogue state that no request can alter, so re-verifying them per
 * acquisition re-verifies a constant.
 *
 * **THIS DOCBLOCK SAID *EVERY* PROPERTY WAS, AND THAT WAS FALSE.** Round 2's security lens found that
 * `assertConnectionCannotBypassPolicies()` composes assertions that are per-SESSION rather than catalogue --
 * NO NUMBER IS WRITTEN HERE, and that is round 5's R5K-5: this sentence said "three" while the enumeration two
 * words later named two, its own inline comment said two, and the code said two. A count beside the thing it
 * counts is this project's most-recorded defect; the list IS the count. They are
 * `assertNoTenantPinnedOnTheConnection()`, which reads `current_setting()`, and
 * `assertNoSessionLifetimeDataIsMaterialised()`, which reads `pg_my_temp_schema()` and `pg_cursors`. So the sentence justifying the cache was wrong about the very
 * assertions inside it — and the previous version went on to claim the pin was "NOT checkable here at all", which the
 * code disproves by checking it.
 *
 * The pin is the one that matters, because it needs no privilege and no SQL: a DSN carrying
 * `options=-ctwes.tenant_id=<other>` (or `PGOPTIONS`) arrives with a SESSION-scoped setting, and a `ROLLBACK TO
 * SAVEPOINT` then reverts `bind()`'s transaction-local value to it. [Reproduced by the panel on a live cluster: after
 * the rollback the session was bound to another tenant and read that tenant's invoice.]
 *
 * **WHY IT NEVERTHELESS CANNOT SLIP PAST THE CACHE, and why the fix is the key rather than the caching:** `verifyOnce`
 * writes the cache ONLY after every assertion has passed, so a pinned DSN fails its first connection and is never
 * cached as good. And the pin is a property of the DSN, identical on every connection built from it — so it cannot
 * differ between a cached verification and a later acquisition. That second half was true by COINCIDENCE until the
 * `options` fragment was added to {@see self::cacheKeyFor()}: two DSNs differing only in `options` hashed to one key,
 * so a clean connection could have vouched for a pinned one. It now holds by construction.
 *
 * The residual risk is the honest one: a per-session property verified once per TTL window is verified for the
 * SESSION that ran it. That is acceptable for the pin because of the DSN argument above, and it is why a genuinely
 * per-session check must not be added into this cached block without re-reading this paragraph — the reason the
 * correction is here rather than appended below the false sentence.
 *
 * **THE GAP THIS LEAVES, stated rather than left to be discovered:** provisioning that drifts *during* a process's
 * lifetime is not caught until the cached verification expires — a window bounded by `$verificationTtl`, stated in
 * `services.yaml`, and bounded there ONLY BECAUSE this class is now `ResetInterface`. Round 3 found that
 * `$verifiedInThisKernel` sits in front of the pool and is governed by no TTL, so in the resident worker
 * `infra/compose.yaml` actually runs (`--time-limit=3600`) the real window was 3600 s rather than 300 — and that the
 * paragraph below reasons exclusively about PHP-FPM, which this project does not deploy. Two things make that acceptable and neither is "it is unlikely". First, `scripts/gates/schema-tenancy.php`
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
final class ConnectionProvisioningGuardMiddleware implements Middleware, ResetInterface
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

    /** Re-entrancy guard for {@see self::assertNothingCarriedOverOnThisSession()} — see that method for why. */
    private bool $checkingThisSession = false;

    /**
     * Forget this process's own short-circuit between units of work, so the TTL means what it says.
     *
     * **`$verifiedInThisKernel` sits IN FRONT of the pool, so it is not bounded by `$verificationTtl` at all** — and
     * round 3 measured the consequence: the docblock reasons about PHP-FPM, which is not what this project deploys.
     * `infra/compose.yaml` runs the worker and the scheduler with `--time-limit=3600`, so in the one resident runtime
     * that actually exists the array is never re-asked and the real window for an `ALTER ROLE twes BYPASSRLS` to go
     * unnoticed was up to 3600 s — twelve times the documented 300.
     *
     * `ResetInterface` is the ecosystem's answer and Symfony calls it between Messenger messages, so the array now
     * empties on the same boundary `InMemoryTenantContext` and `SessionStateReleaser` reset on. The POOL is untouched:
     * it is the thing the TTL governs, it is shared across processes on purpose, and clearing it here would throw away
     * the ~10.8 ms this cache exists to save on every message rather than once per window.
     */
    public function reset(): void
    {
        $this->verifiedInThisKernel = [];
    }

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
        // `options` IS IN THE KEY, and it was not until round 2. It is the one connection parameter that changes a
        // SESSION-scoped setting — `options=-ctwes.tenant_id=<other>` arrives with a tenant already pinned, which a
        // `ROLLBACK TO SAVEPOINT` can then revert `bind()` to. Two DSNs differing only in `options` previously hashed
        // to the same key, so a clean connection could have vouched for a pinned one. Nothing exploited that (the
        // cache is written only after every assertion passes, so a pinned DSN fails its first connection), which is
        // exactly the kind of by-coincidence safety this project does not build tenancy on.
        return 'twes.tenancy.provisioning.' . sha1(\sprintf(
            '%s@%s:%s/%s?%s',
            \is_scalar($params['user'] ?? null) ? (string) $params['user'] : '?',
            \is_scalar($params['host'] ?? null) ? (string) $params['host'] : '?',
            \is_scalar($params['port'] ?? null) ? (string) $params['port'] : '?',
            \is_scalar($params['dbname'] ?? null) ? (string) $params['dbname'] : '?',
            \is_scalar($params['options'] ?? null) ? (string) $params['options'] : '',
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
        $native = $connection->getNativeConnection();

        if (!$native instanceof \PDO) {
            throw new \LogicException(\sprintf(
                'Cannot verify tenancy provisioning: the driver\'s native connection is %s, not \\PDO. This is what '
                . 'stops the application running as a role that can step around row-level security, so it must not '
                . 'degrade to a no-op. Use the `pdo_pgsql` driver, which is the only one twes-in supports.',
                get_debug_type($native),
            ));
        }

        // **THE PER-SESSION ASSERTIONS RUN ON EVERY ACQUISITION AND ARE NEVER CACHED. This is round 3's R3S-5, and
        // the previous shape was defeated by a mechanism the docblock named in parentheses.**
        //
        // Two of the checks `assertConnectionCannotBypassPolicies()` composes are properties of the SESSION rather
        // than of the catalogue: `assertNoTenantPinnedOnTheConnection()` reads `current_setting()`, and
        // `assertNoSessionLifetimeDataIsMaterialised()` reads `pg_my_temp_schema()` and `pg_cursors`. A cache hit
        // returned before `getNativeConnection()` was even called, so it skipped both.
        //
        // Round 2 tried to close that by putting `options` in the cache key, and round 3 measured that the fix guards
        // a route the shipped driver cannot take: DBAL's `PDO\PgSQL\Driver::constructPdoDsn()` emits only
        // `host, port, dbname, sslmode, sslrootcert, sslcert, sslkey, sslcrl, application_name, gssencmode` — never
        // `options`. The route that DOES pin a tenant is `PGOPTIONS` in the process environment, which is not a
        // connection parameter at all and therefore cannot be keyed on. The panel then reproduced a pinned process
        // reading another tenant's row inside a transaction the application believed was unbound, purely on a warm
        // entry written by a clean one — and the pool is `@cache.app`, cross-process and shared by the `api`, `worker`
        // and `scheduler` services through one volume.
        //
        // So the key is no longer the control. Splitting the work is: the CATALOGUE assertions are the expensive ones
        // (~10.8 ms of `pg_authid`, `pg_class` and ACL reads, measured) and they really are static per (role,
        // database), so they stay cached. The two per-session ones cost one `SELECT` each and now always run. `options`
        // stays in the key because it is free and correct — it just is not what closes this.
        $this->assertNothingCarriedOverOnThisSession($native);

        if (isset($this->verifiedInThisKernel[$key])) {
            return;
        }

        $item = $this->verifiedProvisioning->getItem($key);

        if ($item->isHit()) {
            $this->verifiedInThisKernel[$key] = true;

            return;
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

    /**
     * The two assertions that are properties of THIS SESSION, run on every acquisition and cached never.
     *
     * A tenant already pinned on the connection (`PGOPTIONS=-c twes.tenant_id=…`, or an `ALTER ROLE … SET`) is
     * SESSION-scoped, so `bind()`'s transaction-local write sits on top of it and a `ROLLBACK TO SAVEPOINT` reverts to
     * it — the panel read another tenant's row that way. A materialised temporary object or open cursor is the same
     * class: `pg_temp` precedes `public` in the search path, and round 11 read one tenant's rows through a temp table.
     *
     * Both are cheap — one `SELECT` each against no catalogue join — which is what makes running them unconditionally
     * affordable and is why the expensive catalogue assertions remain cached.
     *
     * Its own re-entrancy guard, separate from `$verifiedInThisKernel`: that flag now short-circuits only the cached
     * half, so it can no longer protect this one. The queries here run on the `\PDO` handle already in hand, so they
     * cannot reach `connect()`, but the flag costs nothing and makes the property local rather than inferred.
     */
    private function assertNothingCarriedOverOnThisSession(\PDO $native): void
    {
        if ($this->checkingThisSession) {
            return;
        }

        $this->checkingThisSession = true;

        try {
            PostgresRowLevelSecurityIsolation::assertNoTenantPinnedOnTheConnection($native);
            PostgresRowLevelSecurityIsolation::assertNoSessionLifetimeDataIsMaterialised($native);
        } finally {
            $this->checkingThisSession = false;
        }
    }

}
