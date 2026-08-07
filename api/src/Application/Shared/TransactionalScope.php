<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Shared;

/**
 * Run a unit of work atomically. A **port**: the application states that an operation is all-or-nothing, an adapter
 * in `Infrastructure/` knows what that costs.
 *
 * **WHY THIS EXISTS AT ALL, rather than a handler taking a DBAL connection.** `InvoiceRepository::save()` refuses to
 * run outside a transaction, deliberately: a document number is GAPLESS, so the number and the document carrying it
 * must commit together or a rollback leaves a permanent hole in a legal sequence — what a tax authority reads as a
 * suppressed sale. Something therefore has to open the transaction, and it must be the use case, because only the
 * use case knows what belongs in one. `Application/` may not name `Doctrine\DBAL\Connection`
 * (`scripts/gates/layer-dependencies.php` refuses `Twes\Infrastructure` from this layer, and DBAL is the adapter's
 * business either way), so the boundary is an interface here and the driver is in `Infrastructure/`.
 *
 * **WHY IT IS IN `Application/` AND NOT `Domain/`.** Every other port in this codebase — `InvoiceRepository`,
 * `DocumentNumberSequence`, `ClockInterface`, `IdGenerator` — sits beside the domain concept it serves, because each
 * one is something the *domain* needs in order to express a rule. A transaction is not one of those: no invariant in
 * `Domain/` is stated in terms of atomicity, and `Invoice` would be identical if transactions did not exist. What
 * *is* stated in terms of atomicity is the USE CASE — "allocate a number and persist the document that carries it,
 * or do neither" — so this is an application concern and lives with the application.
 *
 * **NOT a repository method, and not `save()` opening its own transaction.** `DoctrineInvoiceRepository` refuses
 * rather than obliging for exactly this reason: a repository that commits makes the atomic case unwritable, because
 * a caller can no longer put two writes in one unit of work. The refusal is the design; this is its other half.
 *
 * **NOT Symfony Messenger's `doctrine_transaction` middleware either**, which is the ecosystem's answer for a
 * handler dispatched over a bus, and it was considered rather than overlooked. It was rejected because it makes the
 * transaction INVISIBLE at the point it matters: a reader of a handler cannot see that they are inside one, and this
 * codebase has a method that throws when they are not. When a use case genuinely becomes asynchronous, that
 * middleware is the right answer for the bus and this port still describes what the handler needs.
 */
interface TransactionalScope
{
    /**
     * Run `$work` in one transaction: commit on return, roll back and re-throw on any `\Throwable`.
     *
     * @template TResult
     *
     * @param callable(): TResult $work
     *
     * @return TResult whatever `$work` returned
     *
     * @throws \Throwable whatever `$work` threw, after the rollback — never swallowed. A use case that failed
     *                    must not look like one that succeeded with no result.
     */
    public function transactional(callable $work): mixed;
}
