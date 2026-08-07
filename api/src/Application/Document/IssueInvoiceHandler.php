<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Application\Document;

use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Document\DocumentNumberAllocator;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\NumberPattern;
use Twes\Domain\Document\PersistedInvoice;

/**
 * Issues a draft invoice: allocate its number from the gapless counter, freeze it, and persist both **atomically**.
 *
 * ## This is the class the whole transactional design exists for
 *
 * Allocating a number and persisting the document that carries it are one unit of work or they are a defect. If the
 * allocation commits and the save does not, the number is spent on nothing and the sequence has a permanent hole —
 * which a tax authority reads as a suppressed sale, and which is why `CLAUDE.md` records `nextval()` as forbidden in
 * the same breath as money-never-being-a-float. If the save commits and the allocation does not, two documents share
 * a number. `InvoiceRepository::save()` and {@see \Twes\Infrastructure\Persistence\Doctrine\PostgresDocumentNumberSequence}
 * both refuse to run outside a transaction precisely so that neither can be called in a way that permits either
 * outcome, and this handler is what supplies the transaction they insist on.
 *
 * **THE READ IS INSIDE THE TRANSACTION TOO**, and not only for symmetry with {@see CreateInvoiceHandler}: the row is
 * locked by the counter's `SELECT … FOR UPDATE` for the life of this transaction, so a read outside it would be a
 * second transaction queueing behind the first.
 *
 * ## The number never comes from anywhere but the allocator
 *
 * `Invoice::issue()` accepts any well-typed `DocumentNumber`, which is correct — a document rehydrated from a
 * database row must be able to carry the number it already has. The consequence is that nothing in the *domain*
 * forces a new number to come from the counter, so it is this layer's obligation, and `build-waves.plan.md` records
 * it as a `completeness-reviewer` **P0** if an application handler ever constructs a `DocumentNumber` directly. It
 * goes through {@see DocumentNumberAllocator}, which is the only thing that consults the sequence and the only thing
 * that checks the counter it was handed is one a document number may legally be built from.
 *
 * ## The pattern is injected as a WIDTH, and that is a placeholder with a deliberate shape
 *
 * How wide a number is rendered — `0000041` versus `41` — is per-tenant configuration that Wave 1 has no settings
 * table for. So the width arrives as a constructor argument wired in `services.yaml`, which makes changing it a
 * visible one-line diff rather than an edit to this class. It governs **new numbers only**: an issued document's
 * rendered string is persisted, and re-reading it derives its own pattern from the stored string, so no later
 * setting can restate a document a client already holds (`CLAUDE.md` § Gotchas, 2026-08-06).
 *
 * **An `int` rather than a `NumberPattern` service, and the pattern is built HERE in the constructor.** A
 * `NumberPattern` in the container is a domain value object the container constructs, which `config/services.yaml`
 * refuses in its own opening comment. Building it in the constructor rather than per call means a misconfigured
 * width — 0, or above `NumberPattern::MAX_WIDTH` — fails when this service is first instantiated instead of on
 * somebody's first attempt to issue an invoice.
 */
final readonly class IssueInvoiceHandler
{
    private NumberPattern $numberPattern;

    /**
     * @param int $numberWidth how wide to render a NEW number; see the class docblock
     *
     * @throws \InvalidArgumentException if the configured width is not one `NumberPattern` accepts
     */
    public function __construct(
        private InvoiceRepository $invoices,
        private DocumentNumberAllocator $numbers,
        private TransactionalScope $transaction,
        int $numberWidth,
    ) {
        $this->numberPattern = NumberPattern::padded($numberWidth);
    }

    /**
     * @return PersistedInvoice|null null when no invoice with that id is visible to the current tenant — which
     *                               covers "does not exist" and "belongs to somebody else", indistinguishably, by
     *                               design rather than by omission
     *
     * @throws \Twes\Domain\Document\Exception\IllegalTransition if the document is not a draft
     * @throws \Twes\Domain\Document\Exception\DocumentCannotBeIssued if it has no lines
     * @throws \InvalidArgumentException if the id is not a canonical UUID
     * @throws \RuntimeException if no tenant is bound
     */
    public function handle(IssueInvoice $command): ?PersistedInvoice
    {
        return $this->transaction->transactional(function () use ($command): ?PersistedInvoice {
            // `findForMutation()` AND NOT `find()`, and this is the difference between a correct invoice sequence
            // and a hole in one. With an ordinary read, two concurrent issues of one draft both see `draft`, both
            // allocate (the counter serialises them, so 1 and 2), both build an issued aggregate from their own
            // stale snapshot, and the second save overwrites the first: the document is numbered 2 and **number 1
            // is allocated to no document at all**, while the client that already got a 200 for invoice 1 finds it
            // renumbered. [Verified against the migrated schema with two live transactions: `allocated=[1,2]
            // on documents=[2]`.] The port states the guarantee; this is the call that asks for it.
            $persisted = $this->invoices->findForMutation($command->documentId);

            if (null === $persisted) {
                return null;
            }

            // THE NUMBER IS ALLOCATED AFTER THE DOCUMENT IS FOUND, AND AFTER IT IS HELD. Two separate reasons, and
            // the second is the load-bearing one:
            //
            // Allocating before knowing the document exists would consume a number for nothing — recoverable today,
            // because the whole transaction rolls back, but correct only for that reason and a real gap the moment
            // anything commits in between.
            //
            // Allocating before HOLDING the document is the defect above, and it is not recoverable by a rollback:
            // both transactions commit, each believing it acted on a draft. The ordering here is therefore part of
            // the gaplessness guarantee rather than tidiness — one lock order for every writer, document then
            // counter, which is also what makes a deadlock between two issues impossible rather than unlikely.
            $issued = $persisted->invoice->issue(
                $this->numbers->allocate(DocumentType::Invoice, $this->numberPattern),
            );

            $this->invoices->save($persisted->identity, $issued);

            // RE-READ rather than returning what was just built, for the reason `CreateInvoiceHandler` gives at
            // length: `NUMERIC` columns re-scale, so the response must be the document as it will be fetched.
            return $this->invoices->find($command->documentId) ?? throw new \LogicException(\sprintf(
                'Document %s was issued and saved, and is not readable back in the same transaction. That is a '
                . 'row-level-security binding fault rather than a missing document, so it is ours.',
                $command->documentId,
            ));
        });
    }
}
