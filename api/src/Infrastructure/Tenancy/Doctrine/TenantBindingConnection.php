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
use Twes\Infrastructure\Tenancy\TenantContext;
use Twes\Infrastructure\Tenancy\TenantIsolationStrategy;

/**
 * **BINDS THE TENANT TO THE DATABASE SESSION WHEN A TRANSACTION OPENS. This is the call site that did not exist.**
 *
 * `PostgresRowLevelSecurityIsolation::bind()` was written in Wave 0, documented everywhere as the primary tenancy
 * control, and **called by nothing**. The MAXIMAL certification round for the Wave 1 boundary found it: all three
 * mentions of `bind()` under `api/src/` were docblock prose, `RequestTenantBinder` set only the in-memory context, and
 * so `twes.tenant_id` was never written on a request's connection. The consequences were total rather than subtle —
 * `POST /api/invoices` answered `SQLSTATE[42501] new row violates row-level security policy`, and a tenant asking for
 * its OWN document got a 404, because the canonical policy compares against a setting that was always NULL.
 *
 * It failed CLOSED, which is the only reason this was an outage rather than a breach. The danger was the repair: the
 * three obvious ways to make that 42501 go away are a session-scoped `set_config`, connecting as the table owner, or
 * dropping `FORCE` — and the repository's own test fixtures contained all three, which is how the defect stayed
 * invisible for three commits.
 *
 * ## Why THIS seam, and not one of the four alternatives
 *
 * `bind()` uses `set_config(..., true)` — **transaction-local** — and refuses outright when `inTransaction()` is
 * false, because `SET LOCAL` outside a transaction is discarded with a warning and would leave the session unscoped
 * while the method appeared to succeed. That single fact eliminates most of the options:
 *
 * - **Not `kernel.request`.** No transaction is open there, so a transaction-local write would evaporate before the
 *   first query. `RequestTenantBinder` therefore establishes the APPLICATION's context and this establishes the
 *   DATABASE's; both are needed and they are not interchangeable.
 * - **Not connection acquisition.** Same problem, plus the only way to make it stick would be session scope — which
 *   is exactly what `bind()`'s own comments refuse, because a session-scoped value survives into whoever gets a
 *   pooled connection next. That is a cross-tenant read on the most innocent possible path.
 * - **Not `DbalTransactionalScope`.** It would work for writes and it would be forgettable: anything opening a
 *   transaction another way — a console command, a future read model, a test — would run unbound. `CLAUDE.md`
 *   § Gotchas requires that forgetting be *impossible* rather than discouraged, which is the same argument that made
 *   tenancy row-level security instead of a Doctrine filter.
 * - **Not per-statement.** `bind()` refuses a second bind inside one transaction (see
 *   `assertSessionTenantIsUnset()`), and rightly: from inside a transaction, a transaction-local value and a
 *   session-scoped pin are indistinguishable, so tolerating a rebind would tolerate the pin.
 *
 * **The driver-level `beginTransaction()` is the one seam that fires exactly once per real transaction.** DBAL's
 * wrapper increments its nesting level and calls the DRIVER's `beginTransaction()` only at level 1; every nested
 * `beginTransaction()` becomes a `SAVEPOINT` issued through `exec()` instead. [Verified: `Connection::beginTransaction()`
 * in `doctrine/dbal` 4.4.] So the "exactly once per transaction" property that `bind()` demands is DBAL's, not
 * something this class has to remember — and it holds for every transaction on this connection however it was opened.
 *
 * ## Why it is a SEPARATE wrapper from `SavepointTenantBindingConnection`
 *
 * That class is the other half of the same lifecycle — it asserts the binding SURVIVED a `ROLLBACK TO SAVEPOINT` —
 * and folding this in would have been defensible. It was rejected for two reasons, both about review rather than
 * taste: the savepoint guard is documented as independently deletable, and folding in would have meant renaming that
 * trio (86 references across 12 tracked files, including a reviewer charter and the build plan). A rename inside a P0
 * fix makes the fix harder to review than the defect it repairs. The cost is two wrappers on one connection, each
 * holding the same two collaborators; that is two constructor arguments, not two copies of a rule.
 *
 * ## Tenant-less transactions are permitted, and that is not a silent skip
 *
 * When no tenant is bound this opens the transaction and writes nothing. It is tempting to refuse instead, and wrong:
 * `TenantContext` documents genuinely tenant-less work — installation, a global health check, a cross-tenant
 * migration — and refusing would break them. The safety does not come from this class in that case, it comes from the
 * policy: `current_setting('twes.tenant_id', true)` is NULL when unset, the canonical predicate then matches no row,
 * and an unbound transaction consequently READS NOTHING AND WRITES NOTHING. [Verified by the certification round
 * against a live cluster: unbound `SELECT count(*) FROM document` → 0; unbound insert → refused.] Belt and braces on
 * top of that, `DoctrineInvoiceRepository` and `PostgresDocumentNumberSequence` both refuse to run with no tenant in
 * the context at all.
 */
final class TenantBindingConnection extends AbstractConnectionMiddleware
{
    public function __construct(
        DriverConnection $connection,
        private readonly TenantIsolationStrategy $isolation,
        private readonly TenantContext $context,
    ) {
        parent::__construct($connection);
    }

    /**
     * @throws \LogicException if the driver's native connection is not a `\PDO`
     * @throws \RuntimeException if the session already carries a tenant id, or the binding does not take
     * @throws \Twes\Infrastructure\Tenancy\Exception\NoCurrentTenant never from here — the tenant-less case returns
     *                                                                early rather than asking `bind()` to refuse
     */
    public function beginTransaction(): void
    {
        // THE TRANSACTION OPENS FIRST, and the order is not stylistic: `bind()` refuses when `inTransaction()` is
        // false, because that is when `SET LOCAL` would be silently discarded. Binding before the BEGIN would either
        // throw or, worse under a different implementation, write a setting that vanishes.
        parent::beginTransaction();

        if (!$this->context->hasTenant()) {
            // See the class docblock: permitted, and safe because row-level security is what refuses, not this.
            return;
        }

        $native = $this->getNativeConnection();

        // NOT A `\PDO` UNDER EVERY DRIVER, and this REFUSES rather than degrading to a no-op — the same call
        // `SavepointTenantBindingConnection` makes, for the same reason and with more at stake. `pdo_pgsql` yields a
        // `\PDO`; the native `pgsql` extension yields a resource, and `TenantIsolationStrategy` takes a `\PDO`. If
        // this branch ever returned quietly, every request would run unbound: reads would see nothing and writes
        // would be refused, which looks like a broken application rather than a missing control — and if the policy
        // were ever relaxed it would silently become a cross-tenant read instead.
        if (!$native instanceof \PDO) {
            throw new \LogicException(\sprintf(
                'Tenant binding cannot run: the driver\'s native connection is %s, not \\PDO. This is the call site '
                . 'that writes twes.tenant_id, so it must not degrade to a no-op — without it every tenant-owned '
                . 'read returns nothing and every write is refused by row-level security. Use pdo_pgsql, or teach '
                . 'TenantIsolationStrategy the other driver\'s connection type.',
                get_debug_type($native),
            ));
        }

        // ANY FAILURE PROPAGATES WITH THE TRANSACTION LEFT OPEN, deliberately. The tidier-looking alternative is to
        // roll back here first, and it makes things worse: DBAL's wrapper has already incremented its nesting level
        // before calling this, so rolling back at the driver level desyncs that counter and the next `commit()`
        // reports a state that never existed. A failed bind is fatal to the request either way — `bind()` only throws
        // when the session already carries a tenant id or the write did not take, both of which mean the connection
        // cannot be trusted — so the honest outcome is to fail loudly and let the connection be discarded.
        $this->isolation->bind($native, $this->context);
    }
}
