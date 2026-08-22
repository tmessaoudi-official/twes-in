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

/**
 * One contact on a client, as the API returns it. **Output only** — the input is {@see NewContactInput}.
 *
 * **THE ID IS PRESENT ON THE WAY OUT AND ABSENT ON THE WAY IN**, which is the whole reason this is a separate class
 * from its input twin rather than one DTO with an optional field. A caller needs the id to refer to this contact
 * later; a caller may not choose it. One class carrying `?string $id` would state neither.
 *
 * **THE ORDER OF A CLIENT'S CONTACTS IS PART OF THE CONTRACT.** It is what the user arranged, it is persisted in a
 * `position` column for that reason, and `DoctrineClientRepository::find()` reads it back with an explicit
 * `ORDER BY`. Do not sort this collection anywhere — client-side or server-side — without deciding to change the
 * contract.
 */
final readonly class ContactResource
{
    public function __construct(
        /** Server-generated, stable, and unique within this client. */
        public string $id,
        /** How a human refers to this person. Never empty. */
        public string $name,
        /** A valid e-mail address, or absent. Never an empty string — an absent e-mail comes back as `null`. */
        public ?string $email,
        /**
         * A telephone number as it was typed, or absent.
         *
         * **DELIBERATELY UNVALIDATED BEYOND ITS LENGTH**, and returned exactly as it was stored. International
         * numbering is not a format this product is willing to be wrong about: extensions, national prefixes and
         * in-country conventions all differ, and a caller whose legitimate number is refused has no recourse.
         */
        public ?string $phone,
    ) {}
}
