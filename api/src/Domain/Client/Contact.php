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
 * A person at a client — EN 16931's **BG-9 BUYER CONTACT**.
 *
 * The field set is the standard's: a contact point name (BT-56), an e-mail (BT-58) and a telephone number
 * (BT-57). Nothing else, for the reason {@see PostalAddress} gives — a column no code reads is what
 * `Version20260820120000` argues against at length.
 *
 * **A CONTACT IS AN ENTITY AND CARRIES ITS OWN ID, WHICH IS THE ONE PLACE THIS DIFFERS FROM `DocumentLine`.**
 * A line is identified by its POSITION on its document — `Invoice::withoutLine(int $position)` — and that is
 * right there, because a line has no life outside the document it sits on and nothing ever refers to one. A
 * contact is referred to: Wave 4 sends a PDF to one, Wave 10 invites one to the client portal. Under positional
 * identity, deleting the first of three contacts silently re-points every stored reference to the remaining
 * two, and for *"who do we e-mail this invoice to"* that is a defect nobody sees until the wrong person
 * receives an invoice. The id makes a reference survive its neighbours changing.
 *
 * **IMMUTABLE, with no mutators at all.** Editing a contact is `withoutContact()` then `withContact()` on the
 * {@see Client} that owns it — the aggregate root is where the uniqueness invariant lives, so a contact that
 * could rename or re-id itself in place would be able to break an invariant it cannot see.
 */
final readonly class Contact
{
    /**
     * The longest a contact's name may be — a **derived** bound, flagged as such because no plan rules it.
     *
     * The same argument as `FixedCharge::MAX_LABEL_LENGTH`: a write endpoint is where an unbounded
     * client-supplied string becomes a real one. 140 rather than 64 because a person's name is prose and
     * not an identifier, and matching {@see PostalAddress::MAX_PART_LENGTH} keeps one number for
     * "a line of human text" rather than a different one per field.
     */
    public const int MAX_NAME_LENGTH = 140;

    /**
     * The longest a telephone number may be.
     *
     * 32 because E.164 caps a number at 15 digits and the rest is punctuation, spaces and an extension —
     * `+216 71 000 000 ext. 1234` is 25. **The format is deliberately NOT validated**: telephone formatting is
     * jurisdictional, users type them with spaces, dots, brackets and country prefixes in half a dozen
     * conventions, and refusing one is a support ticket rather than a caught defect. Contrast the e-mail
     * below, which is validated because an invoice sent to a malformed address is silently not delivered.
     */
    public const int MAX_PHONE_LENGTH = 32;

    public string $name;
    public ?string $email;
    public ?string $phone;

    /**
     * @param string $id a canonical identifier — see {@see Identifier}
     * @param string $name BT-56, the contact point name
     * @param null|string $email BT-58, validated because an invoice sent to a malformed address is silently
     *                           not delivered and nothing in this system watches for a bounce
     * @param null|string $phone BT-57, bounded but deliberately not format-checked
     *
     * @throws \InvalidArgumentException if the id is not canonical, the name is blank or overlong, the e-mail
     *                                   is malformed, or the phone is overlong
     */
    public function __construct(
        public string $id,
        string $name,
        ?string $email,
        ?string $phone,
    ) {
        if (!Identifier::isWellFormed($id)) {
            throw new \InvalidArgumentException(\sprintf(
                'A contact id must be a canonical lowercase-hyphenated UUID, got "%s". Uppercase and the braced '
                . 'or urn forms are refused rather than normalised: two spellings of one id compare unequal as '
                . 'strings, and this value is what a stored reference to this contact resolves through.',
                $id,
            ));
        }

        // TRIMMED ON STORE for the reason `FixedCharge` records: validating a trimmed value and storing the
        // padded one is how ' Amine' and 'Amine' become two people.
        $name = trim($name);

        if ('' === $name) {
            throw new \InvalidArgumentException(
                'A contact needs a name. An unnamed contact is a row nobody can act on — it cannot be chosen '
                . 'from a list, and an invoice addressed to it says nothing about who is expected to pay it.',
            );
        }

        $this->name = self::bounded($name, self::MAX_NAME_LENGTH, 'contact name');
        $this->phone = self::optional($phone, self::MAX_PHONE_LENGTH, 'telephone number');
        $this->email = self::validatedEmail($email);
    }

    /**
     * The e-mail, refusing a malformed one.
     *
     * **`filter_var` RATHER THAN A HAND-ROLLED PATTERN.** It is core PHP, so it adds no Composer dependency and
     * keeps `Domain/`'s zero-dependency invariant intact — the same standard `Identifier`'s `preg_match` meets
     * — and it is not on `no-ambient-calls-in-domain.php`'s banned list, which bans `filter_input` (that one
     * reads a superglobal) and not `filter_var` (a pure function of its argument). A hand-rolled address
     * pattern is a famous way to reject valid addresses.
     *
     * **What it does NOT accept is worth stating**: an internationalised domain in Unicode form is refused, so
     * a client with a non-ASCII domain must supply its punycode spelling. That is a real limitation rather
     * than a hidden one, and the day it bites, the fix is an explicit conversion at the transport boundary
     * rather than loosening this check.
     */
    private static function validatedEmail(?string $email): ?string
    {
        if (null === $email) {
            return null;
        }

        $email = trim($email);

        if ('' === $email) {
            return null;
        }

        if (!\is_string(filter_var($email, \FILTER_VALIDATE_EMAIL))) {
            throw new \InvalidArgumentException(\sprintf(
                'Contact e-mail "%s" is not a valid address. It is checked here rather than at send time '
                . 'because nothing in this system watches for a bounce: a malformed address means an invoice '
                . 'nobody receives and nobody knows was not received.',
                $email,
            ));
        }

        return self::bounded($email, self::MAX_NAME_LENGTH, 'contact e-mail');
    }

    /** An optional part: blank normalises to absent, anything present is bounded. */
    private static function optional(?string $value, int $max, string $what): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);

        return '' === $value ? null : self::bounded($value, $max, $what);
    }

    /**
     * MEASURED IN CHARACTERS, NOT BYTES — a name refused for being in the wrong script is a defect, not a
     * bound. `mb_strlen` with an EXPLICIT encoding rather than the ambient default, which is configuration.
     */
    private static function bounded(string $value, int $max, string $what): string
    {
        $length = mb_strlen($value, 'UTF-8');

        if ($length > $max) {
            throw new \InvalidArgumentException(\sprintf(
                'The %s is %d characters; at most %d are allowed. The bound counts CHARACTERS rather than '
                . 'bytes, so it is the same limit in every script.',
                $what,
                $length,
                $max,
            ));
        }

        return $value;
    }
}
