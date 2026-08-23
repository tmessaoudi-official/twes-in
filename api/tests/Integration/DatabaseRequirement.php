<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Integration;

/**
 * Why the integration suite FAILS rather than SKIPS when its database is not usable.
 *
 * `CLAUDE.md` § "Quality gate" states it as an invariant: *"With no database reachable the integration suite
 * fails rather than passing — deliberately, since a green run that silently skipped the tenancy proof is the
 * worst outcome available."* The suite contradicted that: both helpers called `markTestSkipped()` on any
 * `PDOException`, so a database that was absent, misconfigured or simply had the wrong password produced
 * `OK, but some tests were skipped!` and **exit 0**.
 *
 * That is not hypothetical. It happened on 2026-07-30: this container runs two PostgreSQL clusters (16 and
 * 18) both configured on port 5432, so after a restart the one WITHOUT the tenancy roles won the port. Every
 * connection got `FATAL: password authentication failed for user "twes"`, all 62 integration tests skipped,
 * and the run reported OK. The tenancy proof — the thing standing between this product and a reportable
 * cross-tenant breach — did not execute, and nothing said so above a whisper.
 *
 * This is the same shape `CLAUDE.md` § Gotchas already records three times over (a guard on one write path, a
 * meta-gate reporting 33/33 for a gate that detected nothing, a permission nothing consulted): **a control
 * that silently does not run is worse than one that is openly owed.** So: fail, and say what to do about it.
 *
 * **THERE IS NO LEGITIMATE SKIP IN THIS SUITE — and since 2026-08-23 that is ENFORCED rather than asserted.**
 * `NoLegitimateSkipTest` scans every tracked file in this suite and fails on a call. It exists because the
 * claim below was FALSE for eight rounds while being written here in capitals: round 15 applied the ruling to
 * `superuserConnection()` and nowhere else, leaving eight guards in `TenantIsolationTest` that skipped — seven
 * on a missing env var, one on an unreachable database. Measured rather than inferred: stripping
 * `TWES_TEST_DB_PROBE_OWNER_ROLE` from the configuration produced `OK, but some tests were skipped!` and exit
 * 0 with three mutant-killing security cases silent. A sentence in capitals is not a control, and this file
 * is the one place that should never have needed telling.
 *
 * **The rest of this paragraph said the opposite until round 16.** It
 * read that `TWES_TEST_DB_SUPERUSER` is "documented as optional" and that "the two cases needing it"
 * skip when it is absent. Both halves were wrong: round 15 made the credential REQUIRED and
 * `superuserConnection()` now calls `self::fail()`, and MANY test methods call it — four of them the only
 * evidence that a security fix is load-bearing. The correction reached `api/phpunit.xml` and `CLAUDE.md`
 * and missed the one file whose entire purpose is documenting this invariant, which is the
 * "a correction that does not reach the full set of sites" shape § Gotchas records.
 *
 * **NO COUNT IS WRITTEN HERE, deliberately.** The sentence above said "ELEVEN" for one commit and was wrong
 * when written — and it was written by the very commit that removed the figure from `api/phpunit.xml` on the
 * grounds that counts drift. That figure has now been "one", "nine" and "eleven" across successive rounds and
 * was stale each time, so it is derived rather than restated, exactly as `CLAUDE.md` § "Quality gate" does:
 *
 *     grep -c 'self::superuserConnection()' api/tests/Integration/Tenancy/TenantIsolationTest.php
 *
 * Note that call sites and test METHODS are different tallies — a method may need more than one privileged
 * fixture — so the grep above counts call sites, which is the figure that matters for "is the credential
 * required". Neither number belongs in prose.
 */
final class DatabaseRequirement
{
    /**
     * The failure message for a database this suite could not use.
     *
     * Names the two-cluster trap explicitly, because diagnosing it from
     * `password authentication failed` alone sends a reader hunting for a wrong password that is correct.
     *
     * Step 1 carries `TWES_TEST_DB_SUPERUSER_PASSWORD` explicitly because the provisioning script now REFUSES
     * without it: that script overwrites the password of the cluster-global superuser role in the shared
     * `pg_authid` catalogue, which reaches every database on the cluster and cannot be undone by a re-run, so
     * it demands consent rather than assuming a throwaway cluster. Printing the bare invocation here would
     * hand a developer a command that refuses — the failure message must name the command that WORKS.
     */
    public static function unreachable(\PDOException $exception): string
    {
        return \sprintf(
            "The integration suite could not connect to PostgreSQL, so the tenancy proof did NOT run.\n\n"
            . "  %s\n\n"
            . "This is a FAILURE and not a skip on purpose (CLAUDE.md \u{a7} \"Quality gate\"): a green run that "
            . "silently omitted the tenancy proof is the worst outcome available here.\n\n"
            . "To fix it:\n"
            . "  1. sudo -u postgres env TWES_TEST_DB_SUPERUSER_PASSWORD=postgres \\\n"
            . "       bash scripts/dev/provision-test-database.sh\n"
            . "     (that variable is REQUIRED: the script refuses without it, because it overwrites the "
            . "password of the cluster-global superuser role in the shared pg_authid catalogue, which reaches "
            . "every database on the cluster and no re-run can restore. `postgres` matches the default in "
            . "api/phpunit.xml. It also refuses if the target database already holds relations.)\n"
            . "  2. If the error above is an AUTHENTICATION failure rather than a refused connection, check "
            . "whether TWO clusters are bound to the same port: `pg_lsclusters`. This container ships "
            . "PostgreSQL 16 and 18 both configured on 5432, and whichever wins the port after a restart may "
            . "be the one WITHOUT the tenancy roles. Stop the other: `pg_ctlcluster 16 main stop`.\n"
            . "  3. Or point the suite elsewhere with TWES_TEST_DSN and the role variables in api/phpunit.xml.",
            $exception->getMessage(),
        );
    }
}
