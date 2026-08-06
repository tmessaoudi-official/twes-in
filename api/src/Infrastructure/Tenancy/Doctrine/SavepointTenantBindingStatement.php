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

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use Twes\Infrastructure\Tenancy\TenantContext;
use Twes\Infrastructure\Tenancy\TenantIsolationStrategy;

/**
 * The `prepare()` route to a savepoint rollback, closed for the same reason as `exec()` and `query()`.
 *
 * DBAL never takes this route for its own savepoints — they go through `executeStatement()` with no parameters,
 * which is `exec()` — so every statement this class wraps was prepared by APPLICATION code that wrote
 * `ROLLBACK TO …` as a string. That is unusual, and it is exactly the kind of route
 * `scripts/gates/worker-mode-blocked.sh` was defeated by three times: the defence for leaving it open would be
 * "nobody would do that", which is not a defence.
 *
 * It carries no SQL of its own, because it does not need to: {@see SavepointTenantBindingConnection::prepare()}
 * only wraps a statement whose SQL already matched the predicate, so reaching `execute()` here IS the trigger. That
 * is deliberately different from the connection, which must re-test each statement — a statement's SQL is fixed at
 * prepare time, so re-testing it on every `execute()` would be re-deriving a constant.
 *
 * @see SavepointTenantBindingMiddleware for the grammar-derived predicate
 */
final class SavepointTenantBindingStatement extends AbstractStatementMiddleware
{
    public function __construct(
        Statement $statement,
        private readonly TenantIsolationStrategy $isolation,
        private readonly TenantContext $context,
        // Captured at prepare time rather than fetched here, because `AbstractStatementMiddleware` gives a
        // statement no route back to its connection.
        private readonly mixed $nativeConnection,
    ) {
        parent::__construct($statement);
    }

    /**
     * @throws \RuntimeException if the rollback left the connection bound to a different tenant than the context
     * @throws \LogicException if the driver's native connection is not a `\PDO`
     */
    public function execute(): Result
    {
        $result = parent::execute();

        // AFTER, never before: the divergence is created by the statement this wraps.
        if (!$this->nativeConnection instanceof \PDO) {
            // Same refusal, and the same reasoning, as the connection's. Duplicated rather than shared because the
            // alternative is a static helper on one of these classes that the other reaches across for — and a
            // three-line refusal read twice is cheaper to trust than an indirection. If a third caller appears,
            // extract it then.
            throw new \LogicException(\sprintf(
                'The savepoint tenant-binding guard cannot run: the driver\'s native connection is %s, not \\PDO. '
                . 'It must not degrade to a no-op — the failure it catches is a cross-tenant read. Use the '
                . '`pdo_pgsql` driver, which is the only one twes-in supports.',
                get_debug_type($this->nativeConnection),
            ));
        }

        $this->isolation->assertStillBoundTo($this->nativeConnection, $this->context);

        return $result;
    }
}
