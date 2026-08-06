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

use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Twes\Infrastructure\Tenancy\TenantContext;
use Twes\Infrastructure\Tenancy\TenantIsolationStrategy;

/**
 * Re-checks the tenant binding immediately after any statement that reverts to a savepoint.
 *
 * **ALL THREE SQL ENTRY POINTS ARE COVERED, and that is not belt-and-braces.** DBAL's own savepoint operations
 * take the `exec()` path — `executeStatement()` with no parameters calls `$connection->exec($sql)` — so `exec()`
 * alone would cover everything Doctrine emits. `query()` and `prepare()` are covered because APPLICATION code can
 * reach them: a `ROLLBACK TO SAVEPOINT` issued through `prepare()->execute()` is unusual but legal, and "nobody
 * would do that" is the only thing that would defend the gap. `scripts/gates/worker-mode-blocked.sh` was defeated
 * three times by exactly that reasoning applied to routes rather than statements.
 *
 * A savepoint name cannot be a bound parameter, so a prepared savepoint rollback is necessarily a literal string —
 * which is why {@see SavepointTenantBindingStatement} can hold the SQL it was prepared with and needs no
 * parameter inspection.
 *
 * THE CHECK RUNS AFTER, never before: the divergence is *created* by the statement, so a check beforehand would
 * pass on precisely the operation that breaks the binding.
 *
 * THE NATIVE CONNECTION IS WHAT IS HANDED TO THE STRATEGY, because {@see TenantIsolationStrategy} takes a `\PDO` —
 * its own docblock anticipated this ("*when Doctrine lands this becomes its `Connection`*"), and keeping the `\PDO`
 * signature means this change touches no existing caller and no test. It also means the strategy's own
 * `SELECT current_setting(...)` bypasses this wrapper entirely, so there is no re-entrancy to reason about.
 *
 * @see SavepointTenantBindingMiddleware for the grammar-derived predicate and what it deliberately ignores
 */
final class SavepointTenantBindingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $connection,
        private readonly TenantIsolationStrategy $isolation,
        private readonly TenantContext $context,
    ) {
        parent::__construct($connection);
    }

    public function exec(string $sql): int|string
    {
        $affected = parent::exec($sql);

        $this->reassertBindingIfReverted($sql);

        return $affected;
    }

    public function query(string $sql): Result
    {
        $result = parent::query($sql);

        $this->reassertBindingIfReverted($sql);

        return $result;
    }

    public function prepare(string $sql): Statement
    {
        // Wrapped only when it could matter. A wrapper around every prepared statement in the application would
        // add a frame to the hottest path in the system to guard a shape that statement cannot express.
        if (!SavepointTenantBindingMiddleware::revertsToASavepoint($sql)) {
            return parent::prepare($sql);
        }

        return new SavepointTenantBindingStatement(
            parent::prepare($sql),
            $this->isolation,
            $this->context,
            $this->getNativeConnection(),
        );
    }

    /**
     * @throws \RuntimeException if the statement reverted the binding and the context no longer agrees
     */
    private function reassertBindingIfReverted(string $sql): void
    {
        if (!SavepointTenantBindingMiddleware::revertsToASavepoint($sql)) {
            return;
        }

        $native = $this->getNativeConnection();

        // NOT A `\PDO` UNDER EVERY DRIVER, so this is checked — and it FAILS rather than returning quietly, which
        // is the whole reason this branch is worth eleven lines. `pdo_pgsql` yields a `\PDO`; the native `pgsql`
        // extension yields a connection resource, and {@see TenantIsolationStrategy} takes a `\PDO`.
        //
        // The tempting spelling is `if (!$native instanceof \PDO) { return; }` — unreachable in practice, since
        // this project's only supported driver is `pdo_pgsql`. That is precisely the shape `CLAUDE.md` § Gotchas
        // records four times: a control that silently does not run is worse than one that is openly owed. Under
        // the wrong driver this guard cannot see the binding at all, and the failure it exists to catch is a
        // CROSS-TENANT READ — so the correct behaviour is to refuse to run the application, not to wave it through
        // while reporting nothing. The message names the fix rather than the symptom.
        if (!$native instanceof \PDO) {
            throw new \LogicException(\sprintf(
                'The savepoint tenant-binding guard cannot run: the driver\'s native connection is %s, not \\PDO. '
                . 'This guard is what stops a rolled-back savepoint silently rebinding a connection to a previous '
                . 'tenant, so it must not degrade to a no-op — the failure it catches is a cross-tenant read. Use '
                . 'the `pdo_pgsql` driver (a `postgresql://` DATABASE_URL), which is the only one twes-in '
                . 'supports.',
                get_debug_type($native),
            ));
        }

        $this->isolation->assertStillBoundTo($native, $this->context);
    }
}
