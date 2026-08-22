<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Infrastructure\Persistence\Doctrine\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A client contact ROW. See {@see ClientRow} for why the persistence model is separate from the aggregate.
 *
 * **THIS ROW CARRIES BOTH AN `id` AND A `position`, WHICH IS THE ONE PLACE IT DIFFERS FROM `DocumentLineRow`.**
 * That row has a position and no id, because a document line is addressed BY position
 * (`Invoice::withoutLine(int $position)`) and nothing ever refers to a line. A contact is referred to — Wave 4
 * e-mails a PDF to one, Wave 10 invites one to the client portal — so it needs an identity that survives its
 * neighbours being deleted, and it needs an order because that order is what a user arranged and a list that
 * reshuffles itself between two page loads reads as a bug even though no field changed.
 *
 * The primary key is `(company_id, client_id, id)`, which is the schema-level statement of the domain
 * invariant that contact ids are unique WITHIN a client — see `Client`'s constructor, which refuses a
 * duplicate on every path in, rehydration included. The separate
 * `client_contact_position_unique_per_client` index is what makes the ORDER unambiguous.
 */
#[ORM\Entity]
#[ORM\Table(name: 'client_contact')]
class ClientContactRow
{
    /**
     * The tenant, repeated on the child ROW rather than reached through the parent.
     *
     * That looks redundant and is not, for the reason {@see DocumentLineRow} states: row-level security is
     * evaluated PER TABLE, so a child table without its own tenant column cannot be scoped at all — it would
     * be readable through any join the planner chooses. It is also what lets the foreign key be composite.
     */
    #[ORM\Id]
    #[ORM\Column(name: 'company_id', type: 'uuid')]
    public Uuid $companyId;

    #[ORM\Id]
    #[ORM\Column(name: 'client_id', type: 'uuid')]
    public Uuid $clientId;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    public Uuid $id;

    /*
     * **NO ASSOCIATION MAPPING, and the composite foreign key is declared in the MIGRATION instead**, exactly
     * as `DocumentLineRow` explains: the FK columns are also identifier columns, ORM 3 has no
     * `insertable`/`updatable` on `JoinColumn`, so expressing it means derived identity that replaces the
     * scalar fields with an object graph the repository would immediately unpick again. A foreign key is a
     * SCHEMA fact and the database enforces it whether or not the ORM knows; that it is COMPOSITE is asserted
     * by `schema-tenancy.php`, and GOAL 8 of `BehaviouralIsolationTest` attacks it as defence in depth.
     */

    /**
     * The order this contact was arranged in, contiguous from zero within its client.
     *
     * `smallint`, sized from `Client::MAX_CONTACTS` (50), which leaves a factor of about 650 against
     * `smallint`'s 32 767 — raising `MAX_CONTACTS` past that would need a column change, which is why the two
     * are noted together, the same way `DocumentLineRow` notes `MAX_LINES`.
     */
    #[ORM\Column(type: 'smallint')]
    public int $position;

    /** EN 16931's BT-56, the contact point name. `text` for the reason {@see ClientRow::$name} gives. */
    #[ORM\Column(type: 'text')]
    public string $name;

    /**
     * BT-58. Nullable, and deliberately NOT format-checked by the schema.
     *
     * PostgreSQL's regex and PHP's `filter_var($x, FILTER_VALIDATE_EMAIL)` do not agree at the edges, so a
     * CHECK would be a second definition of "valid address" that drifts from the domain's — and the one that
     * rejected would do so with a constraint name where the domain gives a message a user can act on.
     * {@see \Twes\Domain\Client\Contact} owns that rule; the column is bounded in LENGTH only, because a
     * length is a storage fact rather than a business rule.
     */
    #[ORM\Column(type: 'text', nullable: true)]
    public ?string $email;

    /**
     * BT-57. `varchar(32)`, sized from `Contact::MAX_PHONE_LENGTH`.
     *
     * The FORMAT is not checked anywhere, and that is a decision rather than an omission: telephone formatting
     * is jurisdictional, users type numbers with spaces, dots, brackets and prefixes in half a dozen
     * conventions, and refusing one is a support ticket rather than a caught defect.
     */
    #[ORM\Column(type: 'string', length: 32, nullable: true)]
    public ?string $phone;

    /** {@see ClientRow::__construct()} for why a Doctrine entity here has one. */
    public function __construct(
        Uuid $companyId,
        Uuid $clientId,
        Uuid $id,
        int $position,
        string $name,
        ?string $email,
        ?string $phone,
    ) {
        $this->companyId = $companyId;
        $this->clientId = $clientId;
        $this->id = $id;
        $this->position = $position;
        $this->name = $name;
        $this->email = $email;
        $this->phone = $phone;
    }
}
