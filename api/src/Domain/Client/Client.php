<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\Domain\Client;

use Twes\Domain\Shared\Identifier;

/**
 * The party an invoice is addressed to — EN 16931's **BG-7 BUYER**, and the root of its own aggregate.
 *
 * **THIS AGGREGATE CARRIES ITS OWN ID, AND `Invoice` DOES NOT. THE DIFFERENCE IS ARGUED RATHER THAN
 * ACCIDENTAL.** {@see \Twes\Domain\Document\DocumentIdentity} exists because a document's row carries THREE
 * things the aggregate does not — `id`, `type` and `vat_rounding_point` — and the last of those is a
 * PARAMETER to `Invoice::totals()`, which is what makes inclusive-versus-exclusive tax "a parameter, never a
 * parallel class hierarchy". Putting it on the aggregate would make it state that has to be kept consistent
 * with the argument every caller passes. A client has no such second axis: its row carries an id and nothing
 * else the aggregate lacks, so the ordinary DDD shape — an aggregate root holding its own identity — applies,
 * and inventing a `ClientIdentity` to hold one field would be ceremony copied from a case whose reason does
 * not apply here.
 *
 * **THE TENANT IS DELIBERATELY ABSENT**, exactly as it is from `DocumentIdentity`, and for the ruling recorded
 * in `CLAUDE.md` § Gotchas 2026-07-31: tenancy is AMBIENT CONTEXT, not a field. A `company_id` in `Domain/` is
 * a P0; the database-per-tenant mode `TenantIsolationStrategy` exists to allow has no such column at all; and
 * the reductio is decisive — if a tenant had to sit inside this type for safety, it would equally have to sit
 * inside `Contact`, `PostalAddress` and `Money`, and a field every type needs is not a field. The boundary
 * rule does the work instead: no tenant-less path may hydrate an aggregate, enforced by the adapter, which is
 * constructed with the request's `TenantContext` and refuses when none is bound.
 *
 * **A CLIENT IS CREATABLE FROM A NAME ALONE.** The address, the tax identifier and the people are added when
 * somebody knows them. Requiring any of them here would make a client unrepresentable until its whole file was
 * assembled, which is not how anybody enters one — and the genuinely strict question, what an ISSUED document
 * requires, is a different one that belongs to the wave implementing the e-invoicing profiles. See
 * {@see PostalAddress} for the same distinction stated at the address level.
 *
 * **IMMUTABLE like every other domain type here**: the five mutators each `return new self(...)`, which is the
 * property `CLAUDE.md` § Architecture names as the load-bearing reason the persistence model is a separate
 * mutable row class rather than this one mapped directly.
 */
final readonly class Client
{
    /**
     * The longest a client's name may be — a **derived** bound, flagged as such because no plan rules it.
     *
     * 140, matching {@see Contact::MAX_NAME_LENGTH} and {@see PostalAddress::MAX_PART_LENGTH}: one number for
     * "a line of human text" rather than a different one per field, which is a set of numbers that drifts.
     */
    public const int MAX_NAME_LENGTH = 140;

    /**
     * The longest a tax identifier may be.
     *
     * EU VAT identifiers are at most 14 characters after the two-letter country prefix; Tunisia's *matricule
     * fiscal* is 13. 32 leaves room for a jurisdiction nobody has met yet without being unbounded.
     *
     * **THE FORMAT IS DELIBERATELY NOT VALIDATED HERE.** There are as many formats as there are tax
     * authorities, and a client legitimately holds one this application has never seen — refusing it would
     * make that client unrepresentable to protect a rule we do not actually know. Checking a VAT identifier
     * properly means calling VIES, which is a network round trip and therefore an `Infrastructure/` concern
     * belonging to the e-invoicing wave, not an ambient call from a domain constructor.
     */
    public const int MAX_TAX_IDENTIFIER_LENGTH = 32;

    /**
     * The most contacts one client may hold — the same kind of bound as `Invoice::MAX_LINES`.
     *
     * A write endpoint is where an unbounded client-supplied collection becomes a real one, and an unbounded
     * one is a memory and a render-time problem rather than a business rule. 50 is far above any real client's
     * contact list and far below anything that costs.
     */
    public const int MAX_CONTACTS = 50;

    private string $id;
    private string $name;
    private ?string $taxIdentifier;
    private ?PostalAddress $address;

    /** @var list<Contact> */
    private array $contacts;

    /**
     * **NOTHING IS PROMOTED, and that is forced rather than stylistic.** Every field here is normalised before
     * it is stored — trimmed, blank-to-null, bounded — and in a `readonly` class a promoted property is already
     * initialised by the time the body runs, so assigning the normalised value raises *"Cannot modify readonly
     * property"*. Promoting the fields that happen not to need normalisation and declaring the rest would make
     * the two groups look like a distinction that means something, so all five are declared.
     *
     * @param list<Contact> $contacts
     */
    private function __construct(
        string $id,
        string $name,
        ?string $taxIdentifier,
        ?PostalAddress $address,
        array $contacts,
    ) {
        // EVERY PATH INTO THIS TYPE GOES THROUGH THESE GUARDS, rehydration included. `Invoice::fromPersistedState()`
        // sets the precedent, and the reason is that a corrupt row is precisely where a duplicate contact id or an
        // ill-formed identifier would come from -- so a rehydration that skipped the checks would produce a domain
        // object no other code path could have built, and every consumer downstream would be reasoning about a shape
        // this class promises is impossible.
        if (!Identifier::isWellFormed($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A client id must be a canonical lowercase-hyphenated UUID, got "%s". Uppercase and the braced '
                . 'or urn forms are refused rather than normalised: two spellings of one id compare unequal as '
                . 'strings, and this value reaches a WHERE clause.',
                $id,
            ));
        }

        $this->id = $id;
        $this->name = self::validatedName($name);
        $this->taxIdentifier = self::validatedTaxIdentifier($taxIdentifier);
        $this->address = $address;

        if (\count($contacts) > self::MAX_CONTACTS) {
            throw new \InvalidArgumentException(\sprintf(
                'A client may hold at most %d contacts, got %d. The bound is not a business rule — it is the '
                . 'point at which an unbounded caller-supplied collection stops being a list of people and '
                . 'starts being a memory and render-time cost.',
                self::MAX_CONTACTS,
                \count($contacts),
            ));
        }

        // UNIQUENESS IS CHECKED HERE rather than only in `withContact()`, so the rehydration path cannot smuggle a
        // duplicate past it. Two contacts sharing an id make `withoutContact()` ambiguous and make any stored
        // reference -- the person an invoice is e-mailed to -- resolve to whichever the persistence layer happened
        // to return first.
        $seen = [];

        foreach ($contacts as $contact) {
            if (isset($seen[$contact->id])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Contact %s is already on this client. Contact ids are unique within a client: a duplicate '
                    . 'makes removal ambiguous and makes a stored reference to a contact resolve to whichever '
                    . 'row came back first.',
                    $contact->id,
                ));
            }

            $seen[$contact->id] = true;
        }

        // ASSIGNED DIRECTLY, with no `array_values()` re-indexing. The first draft had one and PHPStan reported it
        // as having no effect, which was correct rather than pedantic: every path in reaches here with a genuine
        // list -- `create()` passes `[]`, `withContact()` builds one by spreading, `withoutContact()` re-indexes
        // its own filter result, and `fromPersistedState()` declares one. A defensive re-index would be dead code
        // that LOOKS like a guard, which is worse than no guard: the next reader trusts it. The list-ness is the
        // type system's to enforce at the call sites, and it does.
        $this->contacts = $contacts;
    }

    /**
     * A brand-new client: an identity and a name, and nothing else yet.
     *
     * The id is supplied rather than generated, because generating one would need an `IdGenerator` port and
     * `Domain/` may not read ambient randomness — `random_int()` and `uniqid()` are both on
     * `no-ambient-calls-in-domain.php`'s banned list. The application handler holds the generator and passes
     * the result in, which is the same shape the invoice write path uses.
     *
     * @throws \InvalidArgumentException if the id is not canonical, or the name is blank or overlong
     */
    public static function create(string $id, string $name): self
    {
        return new self($id, $name, null, null, []);
    }

    /**
     * Rebuild a client from what was stored.
     *
     * Named so a reader can see at the call site that no new client is being created, and routed through the
     * SAME constructor guards for the reason that constructor's comment gives.
     *
     * @param list<Contact> $contacts
     *
     * @throws \InvalidArgumentException if any guard refuses what was stored
     */
    public static function fromPersistedState(
        string $id,
        string $name,
        ?string $taxIdentifier,
        ?PostalAddress $address,
        array $contacts,
    ): self {
        return new self($id, $name, $taxIdentifier, $address, $contacts);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function taxIdentifier(): ?string
    {
        return $this->taxIdentifier;
    }

    public function address(): ?PostalAddress
    {
        return $this->address;
    }

    /**
     * The contacts, in the order they were added.
     *
     * ORDER IS PRESERVED and is part of what is persisted: it is the order a user arranged them in, and a list
     * that reshuffles itself between two page loads reads as a bug even though no field changed.
     *
     * @return list<Contact>
     */
    public function contacts(): array
    {
        return $this->contacts;
    }

    public function withName(string $name): self
    {
        return new self($this->id, $name, $this->taxIdentifier, $this->address, $this->contacts);
    }

    /** Passing `null` CLEARS it, which is a different act from never having had one and is a real edit. */
    public function withTaxIdentifier(?string $taxIdentifier): self
    {
        return new self($this->id, $this->name, $taxIdentifier, $this->address, $this->contacts);
    }

    /** Passing `null` clears the address entirely — the all-or-nothing rule {@see PostalAddress} states. */
    public function withAddress(?PostalAddress $address): self
    {
        return new self($this->id, $this->name, $this->taxIdentifier, $address, $this->contacts);
    }

    /**
     * @throws \InvalidArgumentException if a contact with this id is already present, or the list is full
     */
    public function withContact(Contact $contact): self
    {
        return new self(
            $this->id,
            $this->name,
            $this->taxIdentifier,
            $this->address,
            [...$this->contacts, $contact],
        );
    }

    /**
     * Remove one contact BY ITS ID.
     *
     * **AN UNKNOWN ID IS REFUSED RATHER THAN IGNORED.** A silent no-op would let a caller believe it had
     * deleted somebody who is still on the record and still receiving invoices — the same reasoning
     * `document.line_not_found` records: acting on stale state is something the UI must be told about, not
     * something to swallow.
     *
     * @throws \InvalidArgumentException if the id is not canonical, or no contact has it
     */
    public function withoutContact(string $contactId): self
    {
        if (!Identifier::isWellFormed($contactId)) {
            throw new \InvalidArgumentException(\sprintf(
                'A contact id must be a canonical lowercase-hyphenated UUID, got "%s". Checked before the '
                . 'lookup rather than after it, so an ill-formed id is refused as such instead of being '
                . 'reported as a contact that does not exist.',
                $contactId,
            ));
        }

        $remaining = array_values(array_filter(
            $this->contacts,
            static fn(Contact $contact): bool => $contact->id !== $contactId,
        ));

        if (\count($remaining) === \count($this->contacts)) {
            throw new \InvalidArgumentException(\sprintf(
                'Contact %s is not on this client, so there is nothing to remove. Refused rather than ignored: '
                . 'a silent no-op would report a deletion that did not happen, and the person would still be '
                . 'on the record and still receiving invoices.',
                $contactId,
            ));
        }

        return new self($this->id, $this->name, $this->taxIdentifier, $this->address, $remaining);
    }

    private static function validatedName(string $name): string
    {
        // TRIMMED ON STORE, not only on validate -- see `FixedCharge`, where validating the trimmed value and
        // storing the padded one made ' Acme' and 'Acme' two clients.
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException(
                'A client needs a name. It is the one field an invoice cannot be addressed without — EN 16931 '
                . 'makes the buyer name (BT-44) mandatory — and an unnamed client cannot be picked from a list.',
            );
        }

        $length = mb_strlen($name, 'UTF-8');

        if ($length > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'The client name is %d characters; at most %d are allowed. The bound counts CHARACTERS rather '
                . 'than bytes, so it is the same limit in every script.',
                $length,
                self::MAX_NAME_LENGTH,
            ));
        }

        return $name;
    }

    private static function validatedTaxIdentifier(?string $taxIdentifier): ?string
    {
        if (null === $taxIdentifier) {
            return null;
        }

        $taxIdentifier = trim($taxIdentifier);

        if ('' === $taxIdentifier) {
            return null;
        }

        $length = mb_strlen($taxIdentifier, 'UTF-8');

        if ($length > self::MAX_TAX_IDENTIFIER_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'The tax identifier is %d characters; at most %d are allowed. The FORMAT is deliberately not '
                . 'checked — there are as many formats as tax authorities, and refusing an unfamiliar one would '
                . 'make a legitimate client unrepresentable to protect a rule this application does not know.',
                $length,
                self::MAX_TAX_IDENTIFIER_LENGTH,
            ));
        }

        return $taxIdentifier;
    }
}
