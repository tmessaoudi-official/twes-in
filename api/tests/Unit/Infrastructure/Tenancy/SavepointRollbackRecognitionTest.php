<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Tests\Unit\Infrastructure\Tenancy;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twes\Infrastructure\Tenancy\Doctrine\SavepointTenantBindingMiddleware;

/**
 * WHICH SQL REVERTS A TENANT BINDING — the predicate the savepoint guard is built on.
 *
 * **The polarity here is deliberate and it is the whole lesson of `scripts/gates/worker-mode-blocked.sh`**, whose
 * first three versions were each defeated by enumerating something. PostgreSQL accepts **four** spellings of a
 * savepoint rollback and the `SAVEPOINT` keyword is OPTIONAL: [Verified on a live connection — `ROLLBACK TO sp1`,
 * `ROLLBACK TRANSACTION TO SAVEPOINT sp2`, `ROLLBACK WORK TO SAVEPOINT sp3` and a lowercase extra-whitespace form
 * were all accepted.] So `stripos($sql, 'ROLLBACK TO SAVEPOINT')` — the obvious implementation, and the one that
 * matches the SQL Doctrine itself emits — misses three of the four.
 *
 * The rule is therefore derived from the GRAMMAR: a statement that begins with `ROLLBACK` and carries a `TO`
 * clause reverts to a savepoint; one without a `TO` clause ends the whole transaction. That distinction is the
 * load-bearing one, because the two need OPPOSITE handling — see the false cases below.
 */
#[CoversClass(SavepointTenantBindingMiddleware::class)]
final class SavepointRollbackRecognitionTest extends TestCase
{
    /**
     * Statements that revert transaction-local state to a savepoint, so the binding must be re-checked.
     *
     * @return iterable<string, array{string}>
     */
    public static function savepointRollbacks(): iterable
    {
        yield 'what Doctrine itself emits' => ['ROLLBACK TO SAVEPOINT DOCTRINE_2'];
        yield 'the SAVEPOINT keyword is OPTIONAL in PostgreSQL' => ['ROLLBACK TO sp1'];
        yield 'the TRANSACTION noise word' => ['ROLLBACK TRANSACTION TO SAVEPOINT sp2'];
        yield 'the WORK noise word' => ['ROLLBACK WORK TO SAVEPOINT sp3'];
        yield 'lowercase, which a hand-written query may well be' => ['rollback to savepoint sp4'];
        yield 'irregular whitespace' => ["ROLLBACK\n  TO\tSAVEPOINT   sp5"];
        yield 'leading whitespace, as a heredoc produces' => ['   ROLLBACK TO SAVEPOINT sp6'];
        yield 'a trailing semicolon' => ['ROLLBACK TO SAVEPOINT sp7;'];
    }

    #[DataProvider('savepointRollbacks')]
    public function testAStatementThatRevertsToASavepointIsRecognised(string $sql): void
    {
        self::assertTrue(
            SavepointTenantBindingMiddleware::revertsToASavepoint($sql),
            'this reverts transaction-local state, so the tenant binding must be re-checked after it',
        );
    }

    /**
     * Statements that must NOT trigger a re-check, and the first group is the one that matters.
     *
     * **A FULL ROLLBACK MUST NOT BE CHECKED.** It legitimately discards the whole transaction including the
     * binding, and the caller is finished with that unit of work — so `assertStillBoundTo()` would find the GUC
     * empty while the context still holds a tenant and throw on a completely correct code path. That would make
     * the guard fire on every rolled-back request, which is how a control gets switched off. It cannot reach the
     * middleware through DBAL's own `rollBack()` (a distinct method, not exec'd SQL), but application code
     * issuing `ROLLBACK` as a string can, so the predicate has to be right rather than merely lucky.
     *
     * @return iterable<string, array{string}>
     */
    public static function notSavepointRollbacks(): iterable
    {
        yield 'a plain full rollback — discards the binding LEGITIMATELY' => ['ROLLBACK'];
        yield 'a full rollback with the TRANSACTION noise word' => ['ROLLBACK TRANSACTION'];
        yield 'a full rollback with the WORK noise word' => ['ROLLBACK WORK'];
        yield 'a full rollback with a semicolon' => ['ROLLBACK;'];
        // Two-phase commit. Ends a prepared transaction by name and carries no TO clause, so the grammar-derived
        // rule excludes it without needing to know what it is.
        yield 'ROLLBACK PREPARED, which is 2PC and not a savepoint' => ["ROLLBACK PREPARED 'some-txid'"];
        yield 'creating a savepoint changes nothing' => ['SAVEPOINT sp1'];
        // Measured, not assumed: RELEASE does NOT revert a transaction-local setting. [Verified on a live
        // connection: after `RELEASE SAVEPOINT sp1` the value set inside the savepoint was still `tenant-B`.] The
        // plan's own wording said to re-check "after any savepoint release or rollback"; a check after RELEASE
        // could never fire, and a check whose subject cannot exist is the vacuity shape this repo keeps finding.
        yield 'RELEASE does not revert a setting, so there is nothing to re-check' => ['RELEASE SAVEPOINT sp1'];
        yield 'a commit' => ['COMMIT'];
        yield 'an ordinary query' => ['SELECT 1'];
        // The word appears, but not as the statement's verb. Anchoring to the start is what excludes it.
        yield 'a query merely MENTIONING the words' => ["SELECT 'ROLLBACK TO SAVEPOINT' AS label"];
        yield 'an empty string' => [''];
    }

    #[DataProvider('notSavepointRollbacks')]
    public function testAStatementThatRevertsNothingIsNotRecognised(string $sql): void
    {
        self::assertFalse(
            SavepointTenantBindingMiddleware::revertsToASavepoint($sql),
            'a re-check here would fire on a correct code path, which is how a guard gets switched off',
        );
    }
}
