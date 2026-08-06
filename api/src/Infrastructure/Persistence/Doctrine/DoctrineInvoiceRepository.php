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

        // UPSERT on the primary key, so `save()` is idempotent on the identity as the port promises. `ON CONFLICT`
        // names the PRIMARY KEY columns rather than a constraint name, which is what keeps this statement correct if
        // the constraint is ever renamed. `company_id` is in the key and never in the SET list: a document cannot
        // change tenant, and an UPDATE that could rewrite it would be a cross-tenant move performed by our own code.
        $this->connection->executeStatement(
            'INSERT INTO document (company_id, id, type, state, currency, number, number_rendered, vat_rounding_point)'
            . ' VALUES (:company_id, :id, :type, :state, :currency, :number, :number_rendered, :vat_rounding_point)'
            . ' ON CONFLICT (company_id, id) DO UPDATE SET'
            . ' state = EXCLUDED.state, number = EXCLUDED.number, number_rendered = EXCLUDED.number_rendered,'
            // `type` and `vat_rounding_point` are updatable because they live on DocumentIdentity, which a caller
            // supplies on every save; `currency` because Invoice carries it. None of them SHOULD change on an
            // issued document, and none of them can, because the aggregate refuses to mutate once issued —
            // Invoice::issue() is the last transition that alters anything but state.
            . ' type = EXCLUDED.type, currency = EXCLUDED.currency,'
            . ' vat_rounding_point = EXCLUDED.vat_rounding_point',
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
        $tenant = $this->currentTenant('read document ' . $id);

        // VALIDATED BEFORE IT REACHES A QUERY, by the type that owns the rule. Constructing a throwaway
        // `DocumentIdentity` purely to validate would need a type and a rounding point this method does not know, so
        // the check is the same anchored pattern — and it is here rather than delegated because the port promises an
        // `\InvalidArgumentException` for an ill-formed id. `/D` for the reason DocumentIdentity gives: without it
        // PCRE accepts a trailing newline, which would be two unequal strings for one id.
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A document id must be a canonical lowercase-hyphenated UUID, got "%s". Refused here rather than '
                . 'passed to a query: an id is a key, and two spellings of one key compare unequal.',
                $id,
            ));
        }

        $documentRow = $this->connection->fetchAssociative(
            'SELECT company_id, id, type, state, currency, number, number_rendered, vat_rounding_point'
            . ' FROM document WHERE company_id = :company_id AND id = :id',
            ['company_id' => $tenant->toString(), 'id' => $id],
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
