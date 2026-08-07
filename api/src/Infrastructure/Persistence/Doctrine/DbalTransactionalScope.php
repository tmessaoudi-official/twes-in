<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine;

use Doctrine\DBAL\Connection;
use Twes\Application\Shared\TransactionalScope;

/**
 * The {@see TransactionalScope} adapter: one DBAL transaction on the RUNTIME connection.
 *
 * A thin delegation to `Connection::transactional()` rather than a hand-rolled begin/commit/rollback, and the reason
 * is not brevity. DBAL's own implementation restores `autoCommit` state, handles the nesting level, and — the part
 * that matters here — rolls back and re-throws rather than converting the failure into a return value. Writing those
 * three lines again is how the rollback ends up conditional.
 *
 * **THE RUNTIME CONNECTION, never `owner`.** `owner` exists so migrations have a credential that is not the
 * application's; it OWNS the tenant-owned tables and can `DROP POLICY` on every one of them, which is why
 * `scripts/gates/no-owner-connection-in-application.php` refuses any mention of it under `src/`. Autowiring the
 * default `Connection` is what gets that right, so this class deliberately does not name a connection at all.
 *
 * **NESTING IS PERMITTED AND IS NOT A NO-OP.** DBAL implements a nested `beginTransaction()` as a SAVEPOINT, so a
 * `transactional()` inside another one is a savepoint that can be rolled back independently. That is the exact
 * divergence {@see \Twes\Infrastructure\Tenancy\Doctrine\SavepointTenantBindingMiddleware} exists to catch: rolling
 * back to a savepoint taken before the tenant binding discards `set_config(..., true)` and leaves the session
 * unbound while the application believes it is bound. The guard is what makes nesting safe here rather than
 * forbidden, so this class does not need to police it — but a reader adding a nested scope should know which control
 * is holding the rope.
 */
final readonly class DbalTransactionalScope implements TransactionalScope
{
    public function __construct(private Connection $connection) {}

    /**
     * @template TResult
     *
     * @param callable(): TResult $work
     *
     * @return TResult
     *
     * @throws \Throwable
     */
    public function transactional(callable $work): mixed
    {
        // A `Closure` rather than the `callable` the port declares, because DBAL 4's signature is
        // `transactional(Closure $func)`. The port stays `callable` on purpose — it is the wider, more idiomatic
        // type for a caller, and narrowing it would push a DBAL detail into `Application/`. The closure DBAL
        // receives takes a `Connection` argument, which is dropped here: handing the connection to a use case is
        // exactly the outward dependency this port exists to prevent.
        return $this->connection->transactional(static fn(): mixed => $work());
    }
}
