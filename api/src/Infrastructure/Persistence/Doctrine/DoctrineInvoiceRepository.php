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
use Twes\Domain\Document\DocumentIdentity;
use Twes\Domain\Document\DocumentType;
use Twes\Domain\Document\Invoice;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\PersistedInvoice;
use Twes\Infrastructure\Tenancy\TenantContext;
use Twes\Infrastructure\Tenancy\TenantId;

/**
 * The {@see InvoiceRepository} adapter, and the place the tenant boundary rule is enforced.
 *
 * **IT WRITES WITH DBAL, NOT THROUGH THE UNIT OF WORK, and that is a measurement rather than a preference.** The
 * aggregate is stored whole — `final readonly` mutators return new instances, so there is no dirty state to diff —
 * and a whole rewrite means replacing the child rows. Doing that through the `EntityManager` is not merely
 * inefficient, it is **impossible**: removing the line at position 0 and persisting a new line at position 0 in one
 * flush raises `Doctrine\ORM\Exception\EntityIdentityCollisionException` from the identity map, BEFORE any SQL is
 * emitted. [Verified on a real connection against the migrated schema: seeded a document with one line, then
 * `remove()` + `persist()` at the same composite primary key → `EntityIdentityCollisionException`.] The identity map
 * holds one instance per row by design, which is the same mismatch that put the mapping on a separate model in the
 * first place (`CLAUDE.md` § Architecture).
 *
 * So what the ORM mapping is FOR here, stated plainly rather than left as an apparent contradiction: it declares the
 * schema that `doctrine:schema:validate` checks, it types the rows {@see InvoiceMapper} produces and consumes, and it
 * is what a future `doctrine:migrations:diff` reads. It is not the write path, and pretending otherwise would mean
 * either fighting the identity map or abandoning whole-rewrite semantics.
 *
 * **THE TENANT COMES FROM THE CONTEXT, NEVER FROM AN ARGUMENT** — see {@see InvoiceRepository} for why the port
 * cannot declare one and why this is the stronger place for it. Both methods refuse when no tenant is bound, which
 * IS Wave 1's boundary rule: *no tenant-less path may hydrate an aggregate*. Note the refusal is defence in depth
 * rather than the primary control: row-level security already returns nothing to an unbound session, because the
 * canonical policy compares against `current_setting('twes.tenant_id', true)` and that is NULL when unset. The
 * reason to refuse anyway is that "returns nothing" and "there is nothing" are indistinguishable to a caller, and a
 * tenant-less read that quietly yields null is how a cross-tenant report gets written as though it worked.
 */
final readonly class DoctrineInvoiceRepository implements InvoiceRepository
{
    public function __construct(
        private Connection $connection,
        private TenantContext $tenantContext,
        private InvoiceMapper $mapper,
    ) {}

    public function save(DocumentIdentity $identity, Invoice $invoice): void
    {
        $tenant = $this->currentTenant('save document ' . $identity->id);

        // NO TRANSACTION OF ITS OWN, AND A REFUSAL RATHER THAN A CONVENIENCE. A document number is GAPLESS
        // (§ Gotchas 2026-07-31), so the number allocation and the document that carries it must commit together or
        // not at all; a repository that opened and committed its own transaction would make the atomic case
        // unwritable, and the failure mode is a permanent hole in an invoice sequence — what a tax authority reads
        // as a suppressed sale. Refusing is also what makes the three statements below atomic without this class
        // having to reason about nesting.
        if (!$this->connection->isTransactionActive()) {
            throw new \RuntimeException(\sprintf(
                'Cannot save document %s outside a transaction. A document number is gapless, so allocating one and '
                . 'persisting the document that carries it must be a single unit of work — and this method replaces '
                . 'the line and charge rows, which is not atomic on its own. Wrap the call: the caller owns the '
                . 'transaction because only the caller knows what else belongs in it.',
                $identity->id,
            ));
        }

        [$document, $lines, $charges] = $this->mapper->toRows($tenant, $identity, $invoice);

        // **AN ISSUED DOCUMENT'S CONTENT IS IMMUTABLE, AND ONLY ITS STATE MAY MOVE. This branch is what makes the
        // byte-identical-re-download guarantee TRUE rather than nearly true.**
        //
        // The previous version guarded `number` alone — `WHERE document.number IS NULL OR document.number =
        // EXCLUDED.number` — while the same statement's SET list rewrote `number_rendered`, `type`, `currency` and
        // `vat_rounding_point`, and the three statements below `DELETE` and re-`INSERT` every line and charge row with
        // no predicate whatsoever. So once the number matched, every other byte-determining column of a legal document
        // a client already holds was freely rewritable. All three round-2 lenses found it independently, and its own
        // comment claimed the opposite in the same breath: *"the enforcement of the byte-identical-re-download
        // guarantee … ever, by any path"*.
        //
        // `vat_rounding_point` is the one that proves the claim was not merely imprecise. It does not live on the
        // aggregate at all — it lives on the caller-supplied `DocumentIdentity` — so "the aggregate refuses to mutate
        // once issued" could never have covered it, and the two settings declare DIFFERENT TAX on identical lines.
        // [Measured by the panel on two TND lines of 0.003 at 19%: `per_rate_group` → vatTotal 0.001, `per_line` →
        // vatTotal 0.002.] § Gotchas 2026-07-31 classes the per-line VAT allocation rule as unfixable-later precisely
        // because re-rendering must reproduce the figures a client holds.
        //
        // **A PRE-READ AND A BRANCH rather than a wider `WHERE`, and the reason is the CHILD ROWS.** A predicate on the
        // parent upsert cannot protect the lines and charges, because by the time it has run the row carries a number
        // either way and PostgreSQL offers no `OLD` in `RETURNING` — so nothing downstream can tell "was already
        // issued" from "just became issued". Reading the stored number first is what makes that distinction available,
        // and it costs one indexed single-row lookup inside a transaction the caller already holds a lock in.
        //
        // Every legitimate save still passes. An ISSUE reads NULL (a draft has no number) and takes the whole-rewrite
        // path below, correctly. A re-save of the same state matches on every column and moves nothing — and skips a
        // pointless `DELETE`+`INSERT` of identical children, which is a small bonus rather than the point. `cancel()`
        // changes `state` and leaves the number alone, which is exactly what this permits and what a guard phrased as
        // *"an issued row is immutable"* would have broken. What it refuses is a renumber, a re-rendering, a currency
        // change, a type change, a rounding-point change, and any rewrite of the lines or charges.
        $storedNumber = $this->connection->fetchOne(
            'SELECT number FROM document WHERE company_id = :company_id AND id = :id',
            ['company_id' => $document->companyId->toRfc4122(), 'id' => $document->id->toRfc4122()],
        );

        if (null !== $storedNumber && false !== $storedNumber) {
            // STATE ONLY. Every other column is a predicate rather than an assignment, so a caller that changed one
            // gets a refusal instead of a silent partial write — the difference between a control and a convention.
            $moved = $this->connection->executeStatement(
                'UPDATE document SET state = :state'
                . ' WHERE company_id = :company_id AND id = :id'
                . ' AND number = :number AND number_rendered = :number_rendered'
                . ' AND type = :type AND currency = :currency AND vat_rounding_point = :vat_rounding_point',
                [
                    'state' => $document->state,
                    'company_id' => $document->companyId->toRfc4122(),
                    'id' => $document->id->toRfc4122(),
                    'number' => $document->number,
                    'number_rendered' => $document->numberRendered,
                    'type' => $document->type,
                    'currency' => $document->currency,
                    'vat_rounding_point' => $document->vatRoundingPoint,
                ],
            );

            if (0 === $moved) {
                throw new \RuntimeException(\sprintf(
                    'Refusing to rewrite issued document %s: it already carries document number %s, and an issued '
                    . 'document is immutable apart from its state. Something in this save differs from what is '
                    . 'stored — the number, its rendered form, the type, the currency or the VAT rounding point — and '
                    . 'a client may already hold the document as it was rendered. Only a state transition (issue → '
                    . 'cancel) may be saved over an issued row.',
                    $identity->id,
                    (string) $storedNumber,
                ));
            }

            // **AND THE CHILD ROWS ARE COMPARED, BECAUSE IGNORING A CHANGE IS NOT REFUSING ONE.** The comment above
            // claimed this branch *"refuses … any rewrite of the lines or charges"*. It did not: it returned success
            // and discarded the change, which two round-3 lenses reproduced independently — a changed line set
            // accepted, zero statements issued against either child table, and the caller told the save happened.
            //
            // That is worse than the wording, for a reason `Invoice::fromPersistedState()` names: a half-committed
            // child rewrite is reachable, and the repair for it is a whole re-save of the correct aggregate — which
            // this branch accepted and did nothing about, leaving a document that can never be hydrated again. The
            // sibling arm three lines up throws for the analogous case with the reasoning *"a silent no-op would
            // leave the caller believing it had issued a document"*; this arm was doing the opposite.
            //
            // COMPARED rather than rewritten. Rewriting them would defeat the point of the branch, which is that an
            // issued document's content is immutable; and a caller asking to change them is asking for something no
            // legitimate path wants, so the honest answer is a refusal. The comparison is on the ROWS the mapper
            // produced, not on the aggregate, so it sees exactly what would have been written.
            $storedChildren = [
                'document_line' => $this->connection->fetchAllAssociative(
                    'SELECT position, quantity, unit_net, vat_rate FROM document_line'
                    . ' WHERE company_id = :company_id AND document_id = :document_id ORDER BY position',
                    ['company_id' => $document->companyId->toRfc4122(), 'document_id' => $document->id->toRfc4122()],
                ),
                'document_charge' => $this->connection->fetchAllAssociative(
                    'SELECT position, label, amount FROM document_charge'
                    . ' WHERE company_id = :company_id AND document_id = :document_id ORDER BY position',
                    ['company_id' => $document->companyId->toRfc4122(), 'document_id' => $document->id->toRfc4122()],
                ),
            ];

            // NUMERICALLY, NOT BY STRING, for every amount and quantity. `NUMERIC(21,6)` returns `'1.000000'` for a
            // stored `'1'`, so a string comparison would refuse an identical re-save — the exact class of false
            // failure `DoctrineInvoiceRepositoryTest`'s docblock records being committed once already.
            $incomingLines = array_map(static fn(Entity\DocumentLineRow $line): array => [
                'position' => $line->position,
                'quantity' => $line->quantity,
                'unit_net' => $line->unitNet,
                'vat_rate' => $line->vatRate,
            ], $lines);
            $incomingCharges = array_map(static fn(Entity\DocumentChargeRow $charge): array => [
                'position' => $charge->position,
                'label' => $charge->label,
                'amount' => $charge->amount,
            ], $charges);

            if (!self::childRowsAgree($storedChildren['document_line'], $incomingLines, ['quantity', 'unit_net', 'vat_rate'])
                || !self::childRowsAgree($storedChildren['document_charge'], $incomingCharges, ['amount'])
            ) {
                throw new \RuntimeException(\sprintf(
                    'Refusing to rewrite issued document %s: it already carries document number %s, and its lines and '
                    . 'fixed charges are part of the document a client may already hold. Only a state transition '
                    . '(issue → cancel) may be saved over an issued row — and this save proposes a different line or '
                    . 'charge set, which is refused rather than silently discarded.',
                    $identity->id,
                    (string) $storedNumber,
                ));
            }

            return;
        }

        // UPSERT on the primary key, so `save()` is idempotent on the identity as the port promises. `ON CONFLICT`
        // names the PRIMARY KEY columns rather than a constraint name, which is what keeps this statement correct if
        // the constraint is ever renamed. `company_id` is in the key and never in the SET list: a document cannot
        // change tenant, and an UPDATE that could rewrite it would be a cross-tenant move performed by our own code.
        $written = $this->connection->executeStatement(
            'INSERT INTO document (company_id, id, type, state, currency, number, number_rendered, vat_rounding_point)'
            . ' VALUES (:company_id, :id, :type, :state, :currency, :number, :number_rendered, :vat_rounding_point)'
            . ' ON CONFLICT (company_id, id) DO UPDATE SET'
            . ' state = EXCLUDED.state, number = EXCLUDED.number, number_rendered = EXCLUDED.number_rendered,'
            // `type` and `vat_rounding_point` are updatable because they live on DocumentIdentity, which a caller
            // supplies on every save; `currency` because Invoice carries it. None of them SHOULD change on an
            // issued document, and none of them can, because the aggregate refuses to mutate once issued —
            // Invoice::issue() is the last transition that alters anything but state.
            . ' type = EXCLUDED.type, currency = EXCLUDED.currency,'
            . ' vat_rounding_point = EXCLUDED.vat_rounding_point'
            // **A DOCUMENT NUMBER IS WRITE-ONCE, AND THIS IS THE RACE-SAFE HALF OF THAT.** Reaching this statement
            // means the pre-read above saw no number, so the row is a draft or does not exist. The predicate is kept
            // anyway, because the pre-read and this statement are two steps: a caller that did NOT take the row lock
            // — `findForMutation()` is the port's guarantee, not something this class can compel — could have another
            // transaction issue the document in between. Then `document.number` is set, neither arm holds, zero rows
            // are written and the throw below fires. So the branch above is the RULE and this is the backstop
            // against the interleaving, which is the shape R1-2 was: two concurrent issues, each acting on a read
            // that was true when it was taken.
            //
            // A `WHERE` on `DO UPDATE` rather than a CHECK or a trigger, because only this form can compare the
            // EXISTING row to the incoming one. A CHECK sees one row; a trigger would put business meaning in a
            // persistence hook, which § "The Symfony ecosystem is the ONLY vocabulary" refuses outright.
            . ' WHERE document.number IS NULL OR document.number = EXCLUDED.number',
            [
                'company_id' => $document->companyId->toRfc4122(),
                'id' => $document->id->toRfc4122(),
                'type' => $document->type,
                'state' => $document->state,
                'currency' => $document->currency,
                'number' => $document->number,
                'number_rendered' => $document->numberRendered,
                'vat_rounding_point' => $document->vatRoundingPoint,
            ],
        );

        // ZERO ROWS WRITTEN MEANS ANOTHER TRANSACTION ISSUED THIS DOCUMENT BETWEEN THE PRE-READ AND THIS STATEMENT.
        // Nothing else can produce it: the row exists (or `ON CONFLICT` would have inserted it), the pre-read saw no
        // number, and the predicate only fails once one is present. Throwing rather than returning quietly is what
        // makes the guard a guard — a silent no-op would leave the caller believing it had issued a document, commit
        // the number it allocated, and produce exactly the hole this exists to prevent.
        if (0 === $written) {
            throw new \RuntimeException(\sprintf(
                'Refusing to renumber document %s: it already carries a different document number. A number is '
                . 'write-once — a client holding invoice N must never find it renumbered — so this is either a stale '
                . 'read of a document another transaction has since issued (load it with findForMutation() before '
                . 'allocating) or an attempt to rewrite a legal document\'s identity.',
                $identity->id,
            ));
        }

        // DELETE-THEN-INSERT, scoped by tenant AND document. The tenant predicate is redundant under row-level
        // security and written anyway: it costs nothing, it makes the statement correct when read in isolation, and
        // the composite index it uses starts with company_id.
        foreach (['document_line', 'document_charge'] as $childTable) {
            $this->connection->executeStatement(
                \sprintf('DELETE FROM %s WHERE company_id = :company_id AND document_id = :document_id', $childTable),
                [
                    'company_id' => $document->companyId->toRfc4122(),
                    'document_id' => $document->id->toRfc4122(),
                ],
            );
        }

        foreach ($lines as $line) {
            $this->connection->executeStatement(
                'INSERT INTO document_line (company_id, document_id, position, quantity, unit_net, vat_rate)'
                . ' VALUES (:company_id, :document_id, :position, :quantity, :unit_net, :vat_rate)',
                [
                    'company_id' => $line->companyId->toRfc4122(),
                    'document_id' => $line->documentId->toRfc4122(),
                    'position' => $line->position,
                    'quantity' => $line->quantity,
                    'unit_net' => $line->unitNet,
                    'vat_rate' => $line->vatRate,
                ],
            );
        }

        foreach ($charges as $charge) {
            $this->connection->executeStatement(
                'INSERT INTO document_charge (company_id, document_id, position, label, amount)'
                . ' VALUES (:company_id, :document_id, :position, :label, :amount)',
                [
                    'company_id' => $charge->companyId->toRfc4122(),
                    'document_id' => $charge->documentId->toRfc4122(),
                    'position' => $charge->position,
                    'label' => $charge->label,
                    'amount' => $charge->amount,
                ],
            );
        }
    }

    public function find(string $id): ?PersistedInvoice
    {
        return $this->load($id, lockRow: false);
    }

    public function findForMutation(string $id): ?PersistedInvoice
    {
        // REFUSED OUTSIDE A TRANSACTION, because `FOR UPDATE` outside one is worse than useless. PostgreSQL takes the
        // row lock and releases it at the end of the implicit single-statement transaction, so the method would
        // return successfully having guaranteed nothing at all — and the caller's whole reason for choosing this over
        // `find()` is a guarantee that lasts until it commits. The port says so; this is where it is enforced, next
        // to `save()`'s refusal for the same reason and with the same shape.
        if (!$this->connection->isTransactionActive()) {
            throw new \RuntimeException(\sprintf(
                'Cannot load document %s for mutation outside a transaction. The row lock this takes is released '
                . 'when the statement\'s implicit transaction ends, so it would guarantee nothing while appearing to '
                . 'succeed — and the caller asked for this method precisely because it is about to allocate a '
                . 'document number, which cannot be given back. Wrap the call.',
                $id,
            ));
        }

        return $this->load($id, lockRow: true);
    }

    /**
     * ONE implementation for both readers, differing only in whether the document row is locked.
     *
     * A parameter rather than two methods with two copies of the query: the SELECT list, the `type` predicate and
     * the hydration are correctness rules — the `type` predicate especially, which is what stops a quote being
     * served as an invoice — and a second copy is how one of them gets fixed and the other does not. `CLAUDE.md`
     * § Gotchas records that shape repeatedly; here it would be a tenancy-adjacent rule diverging silently.
     */
    private function load(string $id, bool $lockRow): ?PersistedInvoice
    {
        $tenant = $this->currentTenant('read document ' . $id);

        // VALIDATED BEFORE IT REACHES A QUERY, by the type that owns the rule — `DocumentIdentity::isWellFormedId()`,
        // which is now the ONE definition of it. This method previously carried its own copy of the anchored pattern,
        // because constructing a throwaway `DocumentIdentity` purely to validate would need a type and a rounding
        // point it does not know; a public predicate on that class gives the delegation without the throwaway object.
        // The refusal itself stays here, with this message, because the port promises an `\InvalidArgumentException`.
        if (!DocumentIdentity::isWellFormedId($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A document id must be a canonical lowercase-hyphenated UUID, got "%s". Refused here rather than '
                . 'passed to a query: an id is a key, and two spellings of one key compare unequal.',
                $id,
            ));
        }

        // FILTERED BY TYPE, and that predicate is a correctness fix rather than an optimisation. Without it this
        // method returned ANY document sharing the id — a quote or a credit note — as a `PersistedInvoice`, so
        // `GET /api/invoices/{quoteId}` would have served a quote rendered as an invoice, and issuing one would have
        // allocated a number from the INVOICE sequence for a document of another type. Unreachable today because no
        // other type is created yet, which is precisely why it had to be closed before one is.
        //
        // Here rather than in the provider or the processor, because both would need it and two copies of one rule
        // drift. The port is `InvoiceRepository`: an invoice is the only thing it is contracted to find.
        // `FOR UPDATE` ON THE PARENT ROW ONLY, and only for a caller that asked. It is the FIRST statement such a
        // caller issues against `document`, which is what makes it the serialiser: a competing issue blocks here,
        // before it can touch the counter, so it never takes the number it would have had to waste. The lines and
        // charges below are deliberately NOT locked — the aggregate's consistency boundary is the document, every
        // writer reaches the children only through it, and `FOR UPDATE` on the child queries would take locks that
        // change nothing while enlarging what a reader can wait behind.
        $documentRow = $this->connection->fetchAssociative(
            'SELECT company_id, id, type, state, currency, number, number_rendered, vat_rounding_point'
            . ' FROM document WHERE company_id = :company_id AND id = :id AND type = :type'
            . ($lockRow ? ' FOR UPDATE' : ''),
            ['company_id' => $tenant->toString(), 'id' => $id, 'type' => DocumentType::Invoice->value],
        );

        if (false === $documentRow) {
            // NULL, not an exception. Under row-level security another tenant's document is indistinguishable from
            // one that does not exist — see the port. The tenant predicate above is belt and braces on top of that.
            return null;
        }

        // NO `ORDER BY position`. The mapper sorts by position itself, and `InvoiceMapperTest` proves it does by
        // handing it reversed rows — so ordering here would hide a defect in the code that is contracted to do it.
        // Round 22 established the direction: the mapper must not depend on arrival order.
        $lineRows = $this->connection->fetchAllAssociative(
            'SELECT company_id, document_id, position, quantity, unit_net, vat_rate'
            . ' FROM document_line WHERE company_id = :company_id AND document_id = :document_id',
            ['company_id' => $tenant->toString(), 'document_id' => $id],
        );

        $chargeRows = $this->connection->fetchAllAssociative(
            'SELECT company_id, document_id, position, label, amount'
            . ' FROM document_charge WHERE company_id = :company_id AND document_id = :document_id',
            ['company_id' => $tenant->toString(), 'document_id' => $id],
        );

        [$identity, $invoice] = $this->mapper->toAggregate($tenant, [
            RowHydrator::document($documentRow),
            array_map(RowHydrator::line(...), $lineRows),
            array_map(RowHydrator::charge(...), $chargeRows),
        ]);

        return new PersistedInvoice($identity, $invoice);
    }

    /**
     * Do two child-row sets describe the same thing? Numerically for the decimal columns, exactly for the rest.
     *
     * **NUMERICALLY IS THE WHOLE POINT.** `quantity` is `NUMERIC(21,6)` and `unit_net` is `NUMERIC(19,4)`, so a stored
     * `'1'` comes back as `'1.000000'` — the same number, a different string. A string comparison here would refuse an
     * identical re-save, which is exactly the false failure `DoctrineInvoiceRepositoryTest`'s docblock records having
     * been committed once already in the opposite direction.
     *
     * Both sides arrive ordered by `position`, and a length mismatch is a mismatch — a removed or added line changes
     * the document as surely as an edited one.
     *
     * @param list<array<string, mixed>> $stored
     * @param list<array<string, string|int>> $incoming
     * @param list<string> $decimalColumns columns to compare with `bccomp` rather than `===`
     */
    private static function childRowsAgree(array $stored, array $incoming, array $decimalColumns): bool
    {
        if (\count($stored) !== \count($incoming)) {
            return false;
        }

        foreach ($incoming as $index => $row) {
            foreach ($row as $column => $value) {
                $storedValue = $stored[$index][$column] ?? null;

                if (\in_array($column, $decimalColumns, true)) {
                    // SCALE 6, the widest of the decimal columns involved, so a comparison never truncates a digit
                    // that distinguishes two values.
                    if (0 !== bccomp((string) $value, (string) $storedValue, 6)) {
                        return false;
                    }

                    continue;
                }

                if ((string) $value !== (string) $storedValue) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * The bound tenant, or a refusal. **This is Wave 1's boundary rule.**
     *
     * *No tenant-less path may hydrate an aggregate* — and the message says what to do, because the legitimate
     * tenant-less operations exist (installation, a global health check, a cross-tenant migration) and their authors
     * need to know this is not the tool for them rather than that something is broken.
     *
     * @throws \RuntimeException if no tenant is bound
     */
    private function currentTenant(string $attempted): TenantId
    {
        if (!$this->tenantContext->hasTenant()) {
            throw new \RuntimeException(\sprintf(
                'Refusing to %s with no tenant bound. An aggregate may only be loaded or stored inside a tenant '
                . '(Wave 1 boundary rule): a tenant-less read returns nothing under row-level security, which is '
                . 'indistinguishable from "there is nothing" and is how a cross-tenant report gets written as though '
                . 'it worked. Genuinely cross-tenant work — installation, a health check, a migration — does not go '
                . 'through this repository.',
                $attempted,
            ));
        }

        return $this->tenantContext->tenantId();
    }
}
