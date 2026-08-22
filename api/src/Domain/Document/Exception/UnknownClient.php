<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Document\Exception;

/**
 * A document names a client that does not exist — or that exists for somebody else.
 *
 * ## The two cases are ONE answer, deliberately
 *
 * *"no such client"* and *"that client belongs to another company"* are indistinguishable here, and that is the
 * design rather than a limitation of the mechanism. The composite foreign key is
 * `(company_id, client_id) → client (company_id, id)`, so a client id belonging to another tenant fails it exactly
 * as an invented one does. Telling the two apart would hand a caller an existence oracle over every other tenant's
 * client ids — the same reasoning `ClientProvider` uses to answer 404 for both a malformed id and an absent one.
 *
 * ## Why a DOMAIN exception for something only the database can detect
 *
 * The rule — *a document may only name a client of its own company* — is a domain rule; the foreign key is merely
 * where it is enforced, because enforcement in the database is what makes forgetting impossible (§ Gotchas
 * 2026-07-29, the argument that put tenancy in row-level security rather than in a Doctrine filter). A repository
 * that let `ForeignKeyConstraintViolationException` escape would make every caller depend on DBAL to discover a
 * rule stated in `Domain/`, and the HTTP layer would then have to catch an infrastructure type to produce a 422.
 *
 * `\DomainException` rather than `\LogicException`: this is the CALLER's error and it is fixable — pick a client
 * that exists — which is what `CLAUDE.md` § "Translation keys" makes the test for whether a refusal is user-facing
 * at all. Its key is `document.client_unknown`.
 */
final class UnknownClient extends \DomainException
{
    public static function withId(string $clientId, ?\Throwable $previous = null): self
    {
        return new self(
            \sprintf(
                'No client %s exists for this company, so no document can be addressed to it. A document may only '
                . 'name a client of its own company — an id belonging to another company is refused in exactly the '
                . 'same way as one that exists nowhere, because distinguishing them would reveal whether somebody '
                . 'else holds it. Create the client first, or correct the id.',
                $clientId,
            ),
            0,
            $previous,
        );
    }
}
