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
use Twes\Domain\Client\Client;
use Twes\Domain\Client\ClientRepository;
use Twes\Domain\Shared\Identifier;
use Twes\UI\Http\ApiResource\ClientResource;

/**
 * `GET /api/clients/{id}` — fetch one client belonging to the current tenant.
 *
 * The TRANSLATION lives in {@see ClientRepresentation}, shared with the write path so a create response and a later
 * fetch of the same client cannot describe it differently. What is left here is this operation's own job: turning
 * a route variable into a lookup, and turning every way that lookup can fail into the same answer.
 *
 * @implements ProviderInterface<ClientResource>
 */
final readonly class ClientProvider implements ProviderInterface
{
    public function __construct(
        private ClientRepository $clients,
        private TransactionalScope $transaction,
    ) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws NotFoundHttpException if the id is not a string, is not a canonical UUID, or names no client this
     *                               tenant can see
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ClientResource
    {
        $id = $uriVariables['id'] ?? null;

        // A NON-STRING ID IS A 404, NOT A 500. API Platform hands over whatever matched the route, and an
        // ill-formed id is a caller error rather than a server one — `error.not_found` per `CLAUDE.md`
        // § "Translation keys", where a transport-level refusal gets a transport-level key.
        if (!\is_string($id)) {
            throw new NotFoundHttpException('A client id must be a string.');
        }

        // CHECKED BEFORE THE LOOKUP, NOT BY CATCHING THE REPOSITORY'S REFUSAL, and that ordering is a correctness
        // fix inherited rather than rediscovered. `CLAUDE.md` § Gotchas records `InvoiceProvider` wrapping its whole
        // lookup in `catch (\InvalidArgumentException)` to translate exactly this refusal — and that catch was wide
        // enough to swallow a HYDRATION failure, so a client whose stored data no longer parses answered
        // `404 No such client.` while the row demonstrably existed and nobody was told to investigate. With the
        // check up front there is no catch, so a hydration failure propagates as a 500: `error.internal`, which is
        // what our own fault is supposed to answer.
        //
        // 404 RATHER THAN 400 for a malformed id, deliberately: distinguishing "malformed" from "absent" tells a
        // prober that its guess had the right SHAPE, which is a small existence oracle for free. Both answers are
        // "no such client".
        if (!Identifier::isWellFormed($id)) {
            throw new NotFoundHttpException('No such client.');
        }

        // **THE READ RUNS IN A TRANSACTION, AND WITHOUT ONE IT RETURNS NOTHING.**
        //
        // The tenant is bound to the database session by `TenantBindingMiddleware`, at `beginTransaction()`, using
        // `set_config(..., true)` — TRANSACTION-LOCAL, because a session-scoped value survives into whoever gets a
        // pooled connection next. So a query issued outside a transaction is issued UNBOUND, the canonical policy
        // compares `company_id` against a NULL setting, and the row is invisible: a tenant asking for its own client
        // would get a 404. That was the state of the whole HTTP surface until 2026-08-07, and the MAXIMAL panel is
        // what found it — no test could, because the two fixtures that exercised the repository each supplied, by
        // hand, one of the two things production was missing.
        //
        // `ClientRepository::find()` refuses outside a transaction rather than answering wrongly, so this wrapper is
        // what keeps that refusal from ever firing.
        $client = $this->transaction->transactional(fn(): ?Client => $this->clients->find($id));

        if (null === $client) {
            // NULL COVERS BOTH "does not exist" AND "belongs to another tenant", indistinguishably, and that is the
            // whole design of row-level security rather than a limitation of it. An error naming the client would
            // confirm its existence to a tenant not entitled to know.
            throw new NotFoundHttpException('No such client.');
        }

        return ClientRepresentation::of($client);
    }
}
