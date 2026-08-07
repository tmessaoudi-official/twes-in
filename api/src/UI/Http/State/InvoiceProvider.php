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
use Twes\Domain\Document\InvoiceRepository;
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
    public function __construct(private InvoiceRepository $invoices) {}

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
            $persisted = $this->invoices->find($id);
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
