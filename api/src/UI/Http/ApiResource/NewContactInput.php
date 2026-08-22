<?php

/*
 * This file is part of twes-in.
 *
 * (c) Takieddine MESSAOUDI <takieddine.messaoudi.official@gmail.com>
 *
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Twes\UI\Http\ApiResource;

use Symfony\Component\Validator\Constraints as Assert;
use Twes\Domain\Client\Contact;

/**
 * One contact inside the body of `POST /api/clients`. **Input only** — the response carries {@see ContactResource}.
 *
 * **NO `id` FIELD.** Contact identity is minted server-side by {@see \Twes\Application\Client\CreateClientHandler}.
 * The existence-oracle argument that keeps a caller from choosing a DOCUMENT id is weaker here — a contact id is
 * scoped to one client, and a collision inside a client is visible only to its owner — so the reason is the plainer
 * one: a caller has no way to know which ids are free, and inventing a rule for a supplied one (accept it? refuse a
 * duplicate? treat it as an upsert?) as a side effect of a create endpoint is how a contract acquires a shape
 * nobody argued for. When `PUT` lands it will have to answer that, and it will answer it deliberately.
 *
 * ## Why these constraints exist when the domain already refuses the same things
 *
 * **THEY ARE WHAT MAKES A DOMAIN REFUSAL IN THE HANDLER MEAN OUR FAULT.** {@see CreateClientProcessor} keeps the
 * handler OUTSIDE its `try`, following {@see CreateInvoiceProcessor}: a `\DomainException` from the aggregate is
 * therefore a 500, and that is only correct if nothing the caller can send reaches it. These constraints are what
 * make that true — every bound {@see Contact} enforces is mirrored here, so a payload that would offend the
 * aggregate is answered as a 422 naming `contacts[2].email` before the handler is ever called.
 *
 * The bounds are REFERENCED from the domain constants rather than repeated, so widening one moves both. The
 * messages are Symfony's own defaults, which are translated into all three locales by the framework — see
 * `CLAUDE.md` § "Translation keys" for why the domain's own English messages are not yet.
 */
final readonly class NewContactInput
{
    public function __construct(
        /** How a human refers to this person. Required — an unnamed contact is a row nobody can act on. */
        // `normalizer: 'trim'` for the reason `NewClientInput`'s own `NotBlank` records: without it `"   "` passes
        // the validator and is refused by the aggregate, which the processor runs outside its `try` — a 500 on a
        // payload the caller could have retyped.
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: Contact::MAX_NAME_LENGTH)]
        public string $name,
        /**
         * A valid e-mail address, or absent.
         *
         * `Assert\Email` here and `filter_var(..., FILTER_VALIDATE_EMAIL)` in the domain are two spellings of one
         * rule, and they are not required to agree on every exotic address — this one only has to be no LOOSER
         * than the domain's, or a payload it accepted would reach the handler and 500. It is not: both refuse an
         * address with no `@`, no domain, or an internal newline.
         */
        #[Assert\Email]
        public ?string $email = null,
        /**
         * A telephone number, as typed. Optional.
         *
         * **LENGTH ONLY, deliberately.** International numbering is not a format this product is willing to be
         * wrong about — extensions, national prefixes and in-country conventions all differ, and a caller whose
         * legitimate number is refused has no recourse. The domain takes the same position.
         */
        #[Assert\Length(max: Contact::MAX_PHONE_LENGTH)]
        public ?string $phone = null,
    ) {}
}
