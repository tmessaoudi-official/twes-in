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
use Doctrine\DBAL\Driver\Middleware;
use Twes\Infrastructure\Tenancy\TenantContext;
use Twes\Infrastructure\Tenancy\TenantIsolationStrategy;

/**
 * RE-CHECK THE TENANT BINDING AT THE SEAM THAT REVERTS IT, so no repository has to remember to.
 *
 * **This is Wave 1's second savepoint decision, and the plan ranked the options for it**
 * (`build-waves.plan.md`): *"Prefer removing the shape to checking for it: either drive the re-check from the
 * savepoint-emitting seam (a DBAL middleware), or forbid savepoint-backed nested transactions in configuration. A
 * check every caller must remember is the weakest of the three."*
 *
 * **One of those three options does not exist, which is why this one was chosen rather than preferred.**
 * `Connection::setNestTransactionsWithSavepoints(false)` throws `InvalidArgumentException` — *"no longer
 * supported"* — and the method is `@deprecated No replacement planned`; `beginTransaction()` at nesting level > 1
 * calls `createSavepoint()` unconditionally. [Verified: read `vendor/doctrine/dbal/src/Connection.php:1005-1012`
 * and `:1048-1064`.] So savepoint-backed nesting cannot be turned off in DBAL 4, and `doctrine.yaml`'s comment
 * about the removed `use_savepoints` key is the same finding from the other side.
 *
 * **WHY A DRIVER MIDDLEWARE IS THE RIGHT SEAM, and not merely an available one.** Every savepoint operation
 * DBAL performs — including the ones it issues for you on a nested `beginTransaction()` — goes through
 * `Connection::executeStatement($platform->…SavePoint($name))` with no parameters, which reaches the *driver*
 * connection as `exec('ROLLBACK TO SAVEPOINT DOCTRINE_2')`. [Verified: `:891-911` and `:1161-1210`.] So one
 * middleware covers BOTH routes: a savepoint the application issued deliberately, and one Doctrine issued on its
 * behalf without the calling code containing the word. A per-repository check covers only the first, and only
 * where somebody remembered.
 *
 * **WHAT IT DELIBERATELY DOES NOT DO: fire on a full rollback.** A plain `ROLLBACK` discards the whole
 * transaction and its binding, legitimately, and the caller is done with that unit of work — so a re-check there
 * would find the GUC empty while the context still holds a tenant, and throw on entirely correct code. A guard
 * that fires on every rolled-back request is a guard somebody switches off. {@see revertsToASavepoint()} is where
 * that distinction lives, and `SavepointRollbackRecognitionTest` pins both directions.
 *
 * **AND IT DOES NOT FIRE ON `RELEASE SAVEPOINT`**, because a release does not revert anything. The plan's wording
 * asked for a re-check *"after any savepoint release or rollback"*; that was measured rather than assumed and the
 * release half is empty. [Verified on a live connection: after `RELEASE SAVEPOINT sp1` a value set inside the
 * savepoint was still present.] A check whose subject cannot exist is the vacuity shape `CLAUDE.md` § Gotchas
 * records four times.
 *
 * The middleware is registered on the **default** connection only. The `owner` connection exists solely so
 * migrations have a credential that is not the application's, migrations are legitimately tenant-less, and adding
 * a tenancy guard to the one connection that must work before any tenant exists buys nothing.
 */
final readonly class SavepointTenantBindingMiddleware implements Middleware
{
    public function __construct(
        // THE PORT, not `PostgresRowLevelSecurityIsolation`. This is the first consumer of the interface method
        // added in the same change, and it is deliberately the proof that the method belongs on the port: under
        // the `database`-per-tenant strategy there is no binding to diverge, and this middleware needs no
        // knowledge of which mode is configured in order to stay correct.
        private TenantIsolationStrategy $isolation,
        private TenantContext $context,
    ) {}

    /**
     * Does this statement revert transaction-local state to a savepoint?
     *
     * **DERIVED FROM THE GRAMMAR, NOT FROM A LIST OF SPELLINGS**, and that is the whole point of the method.
     * PostgreSQL accepts four forms and the `SAVEPOINT` keyword is OPTIONAL — `ROLLBACK TO sp1`,
     * `ROLLBACK TRANSACTION TO SAVEPOINT sp2`, `ROLLBACK WORK TO SAVEPOINT sp3`, plus the form Doctrine emits.
     * [Verified: all four accepted on a live connection.] So the obvious implementation,
     * `stripos($sql, 'ROLLBACK TO SAVEPOINT')`, matches what Doctrine happens to emit and misses three spellings
     * that application code or a hand-written query can produce — the enumeration failure
     * `scripts/gates/worker-mode-blocked.sh` was defeated by three times before its rules were inverted.
     *
     * The grammar's own distinction is simpler than the spellings: a `ROLLBACK` with a `TO` clause returns to a
     * savepoint, and one without it ends the transaction. `ROLLBACK PREPARED 'txid'` is two-phase commit and has
     * no `TO` clause, so it is excluded without the rule needing to know what it is.
     *
     * ANCHORED to the start of the statement, so a query that merely mentions the words in a string literal is
     * not mistaken for one — the false-positive direction that makes a guard usable.
     */
    public static function revertsToASavepoint(string $sql): bool
    {
        return 1 === preg_match('/^\s*ROLLBACK\b(?:\s+(?:TRANSACTION|WORK))?\s+TO\b/i', $sql);
    }

    public function wrap(Driver $driver): Driver
    {
        return new SavepointTenantBindingDriver($driver, $this->isolation, $this->context);
    }
}
