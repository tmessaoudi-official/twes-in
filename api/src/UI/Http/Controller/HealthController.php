<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LIVENESS and READINESS, which are different questions and must not share an endpoint.
 *
 * **Why these are hand-written controllers and not an `#[ApiResource]`.** A liveness probe's job is to answer when
 * other things are broken, so it must not depend on the serialization stack, content negotiation or the OpenAPI
 * metadata being healthy. Routing it through API Platform would make the probe fail for reasons unrelated to
 * whether the process is alive — and an orchestrator would then kill a container that was fine.
 *
 * **Why the split matters to the deployment, not just to purity.** An orchestrator restarts a container that fails
 * LIVENESS and merely stops routing traffic to one that fails READINESS. If a dropped database connection failed
 * liveness, a brief database outage would turn into a restart storm across every replica — the outage would be
 * amplified by the thing meant to detect it. So liveness answers "is this PHP process running" and touches
 * nothing else; readiness answers "can this process serve a real request", which does require the database.
 *
 * **Not authenticated, and deliberately so**, but it discloses nothing a prober could not already infer: no
 * version, no hostname, no connection string, no schema detail, no error text from the driver. The failure body
 * names WHICH check failed and nothing about why, because a readiness endpoint is reachable from wherever the
 * orchestrator lives and this project treats an unauthenticated surface as hostile — see `CLAUDE.md` on the
 * client portal. The detail goes to the log, where it is already privileged.
 */
final readonly class HealthController
{
    /**
     * Every table the first migration creates, so readiness can assert the schema is actually present.
     *
     * Checked by NAME rather than by counting `doctrine_migration_versions`, because a version row says a
     * migration RAN, not that it ran against THIS database — which is the exact confusion `CLAUDE.md` § Gotchas
     * records for 2026-08-01, where a migration exited 0 having migrated a different database entirely.
     */
    private const array REQUIRED_TABLES = [
        'document',
        'document_line',
        'document_charge',
        'document_number_sequence',
    ];

    public function __construct(private Connection $connection) {}

    /**
     * LIVENESS: is this process running and able to execute PHP?
     *
     * Touches nothing external on purpose — see the class docblock on restart storms. If this endpoint can answer,
     * the process is alive, and that is the entire claim.
     */
    #[Route('/health', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'alive'], Response::HTTP_OK);
    }

    /**
     * READINESS: can this process serve a real request?
     *
     * Three checks, each of which has been a real failure in this project's short history:
     *   1. the database answers at all — the container's PostgreSQL has died repeatedly, and a suite that
     *      silently skipped instead of failing is recorded in § Gotchas as the worst outcome available;
     *   2. the migrated schema is PRESENT in the database we are actually connected to — a migration that exits 0
     *      does not tell you which database it migrated;
     *   3. the connection carries NO tenant binding at acquisition. That one is not decoration: FrankenPHP runs
     *      the app in a persistent worker, so a connection is reused across requests, and a tenant left bound on
     *      it is a cross-tenant read for whoever gets it next. `PostgresRowLevelSecurityIsolation` was built for
     *      exactly this window and `assertNoTenantPinnedOnTheConnection()` is the check; readiness is the earliest
     *      place a deployment can notice it.
     */
    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->databaseAnswers(),
            'schema' => $this->schemaIsPresent(),
            'tenant_binding_clean' => $this->connectionCarriesNoTenant(),
        ];

        $ready = !\in_array(false, $checks, true);

        return new JsonResponse(
            ['status' => $ready ? 'ready' : 'not_ready', 'checks' => $checks],
            $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    private function databaseAnswers(): bool
    {
        try {
            return 1 === (int) $this->connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            // Swallowed on purpose: the driver's message names the host, the role and sometimes the database, and
            // this endpoint is unauthenticated. `php_errors.log` is on, so the detail is not lost.
            return false;
        }
    }

    private function schemaIsPresent(): bool
    {
        try {
            // `IN (?)` and NOT `= ANY(?)`: DBAL expands an array parameter into `IN (?, ?, ?, ?)`, so putting it
            // inside `ANY(...)` produces invalid SQL. Caught by curling this endpoint rather than by reading it,
            // which is the whole reason the stack gets stood up instead of only linted.
            $present = $this->connection->fetchFirstColumn(
                'SELECT c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace'
                . " WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p') AND c.relname IN (?)",
                [self::REQUIRED_TABLES],
                [\Doctrine\DBAL\ArrayParameterType::STRING],
            );

            return [] === array_diff(self::REQUIRED_TABLES, $present);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The tenant setting must be UNSET on a freshly acquired connection.
     *
     * `current_setting(..., true)` returns NULL when never set and the EMPTY STRING after a transaction that set
     * it rolled back — both mean "unbound". Anything else means a previous request left a tenant pinned to this
     * connection, which under worker mode is the cross-tenant read this whole scheme exists to prevent.
     */
    private function connectionCarriesNoTenant(): bool
    {
        try {
            $bound = $this->connection->fetchOne(
                \sprintf("SELECT coalesce(current_setting('%s', true), '')", 'twes.tenant_id'),
            );

            return '' === (string) $bound;
        } catch (\Throwable) {
            return false;
        }
    }
}
