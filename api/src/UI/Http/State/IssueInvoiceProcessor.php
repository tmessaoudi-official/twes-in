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
use ApiPlatform\State\ProcessorInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Twes\Application\Document\IssueInvoice;
use Twes\Application\Document\IssueInvoiceHandler;
use Twes\UI\Http\ApiResource\InvoiceResource;

/**
 * `POST /api/invoices/{id}/issue` — the draft→issued transition, which is where a document number is consumed.
 *
 * **WHY A SUB-RESOURCE OPERATION AND NOT A FIELD ON `POST /api/invoices`.** An `"issue": true` flag on create would
 * make issuing reachable two ways, and this domain models `draft()` and `issue()` as distinct because the second one
 * is irreversible in the way that matters: it consumes a number from a gapless legal sequence permanently, and a
 * correction is then a new document rather than an edit. A URL that says `issue` is a request a reader of an access
 * log can recognise.
 *
 * **NO BODY, AND NOTHING TO SUPPLY.** The number, the pattern and the sequence are all the server's; a client that
 * could name any of them could pick a legal document number. `input: false` on the operation is what makes that
 * structural rather than a matter of ignoring fields.
 *
 * **`POST` RATHER THAN `PUT` OR `PATCH`, and it is not an idempotency oversight.** Issuing twice is refused by the
 * aggregate's transition guard (`IllegalTransition` → 422), so the second call is rejected rather than repeated —
 * which is the correct answer for a double-clicked button and the reason `CLAUDE.md` keys
 * `document.illegal_transition` as user-fixable: the page is stale, and reloading shows a document already issued.
 *
 * Two failure answers, and the split matters: **404** for a document this tenant cannot see — which covers "does not
 * exist" and "belongs to somebody else", indistinguishably, because an error naming the document would confirm its
 * existence to a tenant not entitled to know — and **422** for a document that exists and cannot make this
 * transition. See {@see CreateInvoiceProcessor} for why the 422 body carries an English message and what is owed to
 * make it a translation key.
 *
 * @implements ProcessorInterface<null, InvoiceResource>
 */
final readonly class IssueInvoiceProcessor implements ProcessorInterface
{
    public function __construct(private IssueInvoiceHandler $handler) {}

    /**
     * @param array<string, mixed> $uriVariables
     * @param array<string, mixed> $context
     *
     * @throws NotFoundHttpException if no such invoice is visible to the current tenant
     * @throws UnprocessableEntityHttpException if the invoice exists but cannot be issued
     */
    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = [],
    ): InvoiceResource {
        $id = $uriVariables['id'] ?? null;

        // A 404 rather than a 500, for the reason `InvoiceProvider` gives: API Platform hands over whatever matched
        // the route, and a route value that is not a string is a caller error.
        if (!\is_string($id)) {
            throw new NotFoundHttpException('An invoice id must be a string.');
        }

        try {
            $issued = $this->handler->handle(new IssueInvoice($id));
        } catch (\InvalidArgumentException $malformed) {
            // AN ILL-FORMED ID IS A 404, NOT A 400 — the same call `InvoiceProvider` makes and for the same reason:
            // distinguishing "malformed" from "absent" tells an unauthenticated prober that its guess had the right
            // SHAPE, which is a small existence oracle for free.
            //
            // CAUGHT BEFORE `\DomainException` BELOW because it is the narrower case, not because of ordering luck:
            // `\InvalidArgumentException` is a `\LogicException` and the two hierarchies are disjoint, so this arm
            // cannot swallow a transition refusal.
            //
            // WHAT ELSE COULD THEORETICALLY LAND HERE, stated because "no such invoice" would be the wrong answer for
            // it: `DocumentNumber`'s constructor raises `\InvalidArgumentException` for a non-positive sequence. It is
            // unreachable — `DocumentNumberAllocator` checks the counter first and raises `SequenceContractViolated`,
            // a `\LogicException`, precisely so a broken adapter is reported as OUR fault rather than the caller's. If
            // that guard is ever removed, this catch turns a server fault into a 404 and the failure becomes silent.
            throw new NotFoundHttpException('No such invoice.', $malformed);
        } catch (\DomainException $refused) {
            // The document exists and refuses the transition: already issued, already cancelled, or empty. A real
            // 422 — the caller can act on it, and the transaction has already rolled back, so no number was spent.
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        if (null === $issued) {
            throw new NotFoundHttpException('No such invoice.');
        }

        return InvoiceRepresentation::of($issued);
    }
}
