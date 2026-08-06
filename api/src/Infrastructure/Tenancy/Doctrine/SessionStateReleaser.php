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

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;
use Twes\Infrastructure\Tenancy\Exception\ConnectionMustBeEvicted;
use Twes\Infrastructure\Tenancy\PostgresRowLevelSecurityIsolation;

/**
 * `DISCARD ALL` the connection between units of work, so no session state reaches the next tenant.
 *
 * **THERE IS NO CONNECTION POOL IN THIS APPLICATION, and that is what decided the seam.** `build-waves.plan.md`
 * states the obligation as *"`discardSessionState()` when a connection is RETURNED"* and speaks throughout of "the
 * pool" — but PHP-FPM gives one process per request and closes the connection with it, so under HTTP there is no
 * return to intercept. The moment that DOES exist today is Symfony's own: `ServicesResetter` runs between units of
 * work in a long-running process, and Messenger's `ResetServicesListener` invokes it after every message
 * [Verified: `vendor/symfony/messenger/EventListener/ResetServicesListener.php`]. A worker consuming a queue of jobs
 * for DIFFERENT TENANTS on ONE connection is exactly the hazard the obligation describes, and `compose.yaml` runs
 * one. So `ResetInterface` is not a convenient hook, it is the correct one — and using the ecosystem's own reset
 * contract rather than inventing a `release()` is `CLAUDE.md` § "The Symfony ecosystem is the ONLY vocabulary".
 *
 * FrankenPHP worker mode would add a second, per-request trigger. It is deliberately blocked until Wave 10
 * (`scripts/gates/worker-mode-blocked.sh`), and when it lands this service is already the thing that has to run —
 * which is worth knowing, because the recoverable-seed reasoning that blocks worker mode and the session-state
 * reasoning here are the same class of leak: state outliving the request that created it.
 *
 * **IT DOES NOT SWALLOW `ConnectionMustBeEvicted`, and that is the whole point of the type.** A cleanup that itself
 * failed leaves a connection that is not dirty but *unknowable* — it may still carry a temporary table, a
 * `CURSOR WITH HOLD` or a `LISTEN` registration readable under whatever tenant is bound next. The plan is explicit:
 * *"catching and ignoring that exception re-creates the eighth carrier in full."* So this closes the connection and
 * rethrows. Closing FIRST matters: DBAL reconnects lazily on the next query, so a closed connection is the only
 * state from which the next unit of work cannot inherit anything.
 */
final readonly class SessionStateReleaser implements ResetInterface
{
    public function __construct(private Connection $connection) {}

    /**
     * @throws ConnectionMustBeEvicted if the cleanup itself failed, after closing the connection
     */
    public function reset(): void
    {
        // NOT CONNECTED IS THE COMMON CASE and it is not a no-op worth a round trip: `isConnected()` false means no
        // session exists to carry anything, and calling `getNativeConnection()` would CONNECT in order to clean a
        // connection nobody opened. That would turn a reset between two idle messages into a new backend.
        if (!$this->connection->isConnected()) {
            return;
        }

        $native = $this->connection->getNativeConnection();

        // FAILS LOUDLY rather than degrading, for the reason `SavepointTenantBindingConnection` gives at the
        // identical branch: this project's only supported driver is `pdo_pgsql`, and a guard that quietly does not
        // run is worse than one openly owed — here the consequence is one tenant's temporary table being readable
        // under the next tenant's binding.
        if (!$native instanceof \PDO) {
            throw new \LogicException(\sprintf(
                'Cannot release session state: the driver\'s native connection is %s, not \\PDO. This is what stops '
                . 'a temporary table, a held cursor or a LISTEN registration reaching the next tenant on a reused '
                . 'connection, so it must not degrade to a no-op. Use the `pdo_pgsql` driver, which is the only one '
                . 'twes-in supports.',
                get_debug_type($native),
            ));
        }

        try {
            PostgresRowLevelSecurityIsolation::discardSessionState($native);
        } catch (ConnectionMustBeEvicted $evict) {
            // CLOSE, THEN RETHROW. Closing is the eviction — DBAL has no pool to remove it from, and it reconnects
            // lazily, so a closed connection is a connection the next unit of work cannot inherit state through.
            // Rethrowing is what the plan requires: the caller must learn that a connection was in an unknown state,
            // because the alternative is a silent recovery from a tenancy failure.
            $this->connection->close();

            throw $evict;
        }
    }
}
