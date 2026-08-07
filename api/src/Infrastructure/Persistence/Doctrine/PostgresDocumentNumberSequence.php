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
use Twes\Domain\Document\DocumentNumberSequence;
use Twes\Domain\Document\DocumentType;
use Twes\Infrastructure\Tenancy\TenantContext;

/**
 * The gapless per-`(tenant, type)` counter: one ROW, advanced by ONE atomic statement.
 *
 * **A POSTGRESQL `SEQUENCE` IS FORBIDDEN HERE AND THIS CLASS IS WHAT REPLACES IT** (`CLAUDE.md` § Gotchas,
 * 2026-07-31, recorded in the same class of decision as money-never-being-a-float because it is equally unfixable
 * once data exists). `nextval()` is *deliberately* non-transactional: it does not roll back, so every failed or
 * rolled-back issue burns its number and leaves a permanent hole. A missing invoice number is what a tax authority
 * reads as a suppressed sale, and France and Tunisia both audit for it. `SERIAL`, `IDENTITY` and any `CACHE n` fall
 * to the same objection.
 *
 * ## One statement, and the three-statement form was written first and MEASURED to be worse
 *
 * `CLAUDE.md` and this port both described the mechanism as *"a per-`(tenant, type)` counter row taken under
 * `SELECT ... FOR UPDATE` inside the same transaction"*, and that is what this class did first:
 * `INSERT … ON CONFLICT DO NOTHING`, then `SELECT … FOR UPDATE`, then `UPDATE … + 1`. It is correct. It was replaced
 * anyway, and the reason is evidence rather than taste.
 *
 * **Deleting ` FOR UPDATE` from that version left the entire suite green**, including a concurrency case written
 * specifically to kill that mutant, and twice — the second time after the fixture was corrected to commit the counter
 * row first. Measuring it showed why: `INSERT … ON CONFLICT DO NOTHING` **blocks on its own** when a concurrent
 * transaction has already touched that key (`canceling statement due to lock timeout … while inserting index tuple`),
 * so under contention the `SELECT` is never the statement that serialises and the lock is unobservable. The window
 * the lock actually closes is *between* the first session's own `SELECT` and its `UPDATE` — which no test can enter
 * without a harness that interleaves statements inside this method.
 *
 * So the three-statement form was correct **only because of a lock that nothing could prove was there**, which is the
 * shape this repository refuses: § Gotchas records four separate controls that existed and were enforced by nothing.
 * The single statement has no between-statements window to protect, so serialisation stops being a property of code
 * that must remember to take a lock and becomes a property of the statement. [Verified against a real cluster: with
 * a brand-new key it returns 1, 2, 3; after a rollback the next allocation is 1 again; and with two overlapping
 * transactions the second is refused with `55P03` while the first is open and returns the NEXT value — not the same
 * one — once it commits, both for an existing counter and for two sessions racing to create one.]
 *
 * The plan's wording was amended to match rather than left to disagree. Its substance is untouched: not a sequence, a
 * row, inside the caller's transaction, serialised, at an accepted throughput cost.
 *
 * ## Why it refuses outside a transaction
 *
 * **Because the increment must roll back with the document**, and outside a transaction it cannot: each statement is
 * its own implicit transaction, so an issue that fails after the allocation has already committed the number, leaving
 * the permanent hole that `nextval()` was rejected for. That is now the *whole* reason — the earlier version of this
 * paragraph also argued that the lock would be released too early, which was true of the three-statement form and is
 * meaningless for one atomic statement. A false reason in a guard's own message is worse than a terse one.
 *
 * A consequence for anyone writing a test against this: the fixture has to open the transaction. That is not an
 * inconvenience but the production shape — `DocumentNumberAllocator` is called from inside
 * {@see \Twes\Application\Document\IssueInvoiceHandler}'s `TransactionalScope`.
 *
 * ## Accepted cost, ruled explicitly
 *
 * Issues for one `(tenant, type)` **serialise**. Two invoices sharing a number is a worse outcome than a queued
 * request. `PostgresDocumentNumberSequenceTest` proves that rather than asserting it in prose, which is the one
 * guarantee `DocumentNumberSequenceContract` discloses that it cannot check itself.
 */
final readonly class PostgresDocumentNumberSequence implements DocumentNumberSequence
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @throws \RuntimeException if no tenant is bound, if called outside a transaction, or if the counter row
     *                           cannot be read back after being ensured
     */
    public function allocateNext(DocumentType $type): int
    {
        // THE TENANT IS AMBIENT, NEVER A PARAMETER — the port says so and the reason is that `Domain/` must never
        // learn what a tenant is, which is also what keeps the database-per-tenant mode expressible. It is still
        // needed HERE, because under the `column` strategy the counter row is identified by it.
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to allocate a %s number with no tenant bound. A document number is per (tenant, type), so '
                . 'a tenant-less allocation has no counter to advance — and under row-level security the query '
                . 'would silently see no row, which is indistinguishable from a tenant that has issued nothing. '
                . 'That is how a document gets number 1 twice.',
                $type->value,
            ));
        }

        if (!$this->connection->isTransactionActive()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to allocate a %s number outside a transaction. The allocation must roll back with the '
                . 'document that carries it: outside a transaction the increment commits on its own, so an issue '
                . 'that then fails leaves a permanent hole in a gapless legal sequence — which is exactly the '
                . 'defect a PostgreSQL SEQUENCE was rejected for. Wrap the call in a TransactionalScope.',
                $type->value,
            ));
        }

        $tenant = $this->tenantContext->tenantId()->toString();

        // ONE STATEMENT, ATOMIC. See the class docblock for why this replaced an ensure/lock/increment trio, and for
        // the measurements. Three things are happening and each one is load-bearing:
        //
        //   * `VALUES (…, 2)` seeds a NEW counter at 2, not 1, because the column means "the next number to hand
        //     out" and this call is handing out the first one. Guarantee 2 of the port — the first document of a
        //     tenant's life is number 1 — is therefore expressed by `RETURNING next_value - 1` below.
        //   * `DO UPDATE SET next_value = document_number_sequence.next_value + 1` is TABLE-QUALIFIED on purpose.
        //     `EXCLUDED.next_value` is the proposed value (always 2), so using it would make every allocation after
        //     the first return 2 — the duplicate-number failure, written as one wrong word.
        //   * `RETURNING next_value - 1` yields the number just handed out: 1 on the insert path (2 - 1), and the
        //     pre-increment value on the conflict path. Subtracting in PHP would be identical; it is in the SQL so
        //     that the statement is self-contained and the value can never be read from a different row version.
        //
        // Under contention the conflict path takes the row lock and a second session blocks until this transaction
        // ends, then re-reads and increments the COMMITTED value — so the next caller gets the next number rather
        // than the same one. That is PostgreSQL's guarantee for `ON CONFLICT DO UPDATE`, not something this code has
        // to remember to do, which is the whole reason for preferring it.
        $allocated = $this->connection->fetchOne(
            'INSERT INTO document_number_sequence (company_id, type, next_value) VALUES (:company_id, :type, 2)'
            . ' ON CONFLICT (company_id, type)'
            . ' DO UPDATE SET next_value = document_number_sequence.next_value + 1'
            . ' RETURNING next_value - 1',
            ['company_id' => $tenant, 'type' => $type->value],
        );

        if (false === $allocated) {
            // NOT KNOWN TO BE REACHABLE, and thrown rather than assumed away — the same idiom `DocumentLine` uses for
            // its `rescale()` arm, and for the same reason: a documented impossibility gets read once and never
            // re-tested, so the honest form is a guard that says what it would mean.
            //
            // `RETURNING` yields exactly one row for a successful upsert, and a row-level-security refusal raises
            // rather than returning nothing. If this ever fires, the session is bound to a different tenant than this
            // class was told about — and the port requires failing over returning a value we are unsure of, because a
            // guessed document number is a duplicate one.
            throw new \RuntimeException(\sprintf(
                'The %s counter for tenant %s advanced without returning a value. That should be impossible for an '
                . 'upsert with a RETURNING clause; the likeliest cause is that the database session is bound to a '
                . 'DIFFERENT tenant than the application believes, since the statement is filtered by the '
                . 'row-level-security policy comparing against current_setting(\'twes.tenant_id\'). Refusing rather '
                . 'than guessing.',
                $type->value,
                $tenant,
            ));
        }

        // `bigint` comes back as a native int from pdo_pgsql (unlike `numeric`, which is a string) — but the cast is
        // written anyway rather than relying on that, because it is a driver property and not a contract one, and
        // the port's return type is `int`.
        return (int) $allocated;
    }
}
