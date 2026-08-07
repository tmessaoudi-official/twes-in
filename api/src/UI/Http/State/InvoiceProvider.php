<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twes\Application\Shared\TransactionalScope;
use Twes\Domain\Document\InvoiceRepository;
use Twes\Domain\Document\PersistedInvoice;
use Twes\UI\Http\ApiResource\InvoiceResource;

/**
 * `GET /api/invoices/{id}` — fetch one invoice belonging to the current tenant.
 *
 * The TRANSLATION itself lives in {@see InvoiceRepresentation}, shared with the two write processors, because all
 * three answer with the same resource and the figures they assemble must not differ between a create response and a
 * later fetch of the same document. What is left here is this operation's own job: turning a route variable into a
 * lookup, and turning every way that lookup can fail into the same answer.
 *
 * @implements ProviderInterface<InvoiceResource>
 */
final readonly class InvoiceProvider implements ProviderInterface
{
    public function __construct(
        private InvoiceRepository $invoices,
        private TransactionalScope $transaction,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): InvoiceResource
    {
        $id = $uriVariables['id'] ?? null;

        // A NON-STRING ID IS A 404, NOT A 500. API Platform hands over whatever matched the route, and an
        // ill-formed id is a caller error rather than a server one — `error.not_found` per CLAUDE.md
        // § "Translation keys", where a transport-level refusal gets a transport-level key.
        if (!\is_string($id)) {
            throw new NotFoundHttpException('An invoice id must be a string.');
        }

        try {
            // **THE READ RUNS IN A TRANSACTION, and that is not ceremony — without one it returns nothing.**
            //
            // The tenant is bound to the database session by `TenantBindingConnection`, at `beginTransaction()`, using
            // `set_config(..., true)` — TRANSACTION-LOCAL, because a session-scoped value survives into whoever gets a
            // pooled connection next. So a query issued outside a transaction is issued UNBOUND, the canonical policy
            // compares `company_id` against a NULL setting, and the row is invisible: a tenant asking for its own
            // document gets a 404. That was the state of this endpoint until 2026-08-07 and the MAXIMAL panel is what
            // found it.
            //
            // A read-only transaction costs a snapshot and no WAL, which is the price of the transaction-local
            // binding — and the binding is what makes forgetting impossible everywhere else.
            //
            // NO `FetchInvoice` HANDLER, deliberately, and the asymmetry with the write path is worth stating: the
            // write handlers exist because they own decisions (which id, which number, what commits together). A read
            // owns none — it is a lookup and a translation — so a handler would add a layer that only forwards. If a
            // read ever needs to coordinate two aggregates in one snapshot, that is when it earns one.
            $persisted = $this->transaction->transactional(fn(): ?PersistedInvoice => $this->invoices->find($id));
        } catch (\InvalidArgumentException $malformed) {
            // THE REPOSITORY REFUSES AN ILL-FORMED ID BEFORE IT REACHES A QUERY, and that refusal is correct —
            // an id is a key, and two spellings of one key compare unequal. Translated to a 404 rather than a 400
            // deliberately: distinguishing "malformed" from "absent" tells an unauthenticated prober that its
            // guess had the right SHAPE, which is a small oracle for free. Both answers are "no such document".
            throw new NotFoundHttpException('No such invoice.', $malformed);
        }

        if (null === $persisted) {
            // NULL COVERS BOTH "does not exist" AND "belongs to another tenant", indistinguishably, and that is
            // the whole design of row-level security rather than a limitation of it. An error naming the document
            // would confirm its existence to a tenant not entitled to know.
            throw new NotFoundHttpException('No such invoice.');
        }

        return InvoiceRepresentation::of($persisted);
    }
}
