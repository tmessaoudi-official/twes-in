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
 * A client's postal address, as the API returns it. **Output only** — the input is {@see PostalAddressInput}.
 *
 * ## Five fields, from EN 16931 BG-8, and no more
 *
 * `line1`, `line2`, `postcode`, `city`, `countryCode`. That is the invoicing standard's own address, which is the
 * legitimate source under licensing invariant 2 — the standards an invoicing product must implement are not
 * copyrightable expression, and building from them is the whole method of this clean-room reimplementation.
 *
 * **NO `region`/`state` FIELD, stated rather than left to be noticed.** EN 16931 has one (BT-54) and this product
 * does not, because nothing here consumes it yet: neither the PDF pipeline nor the e-invoicing formats are built,
 * so the field would be storage with no reader. It is added in the change that needs it, with a migration — not in
 * anticipation, which is the rule `CompanySettingsResource` states for its own absent third setting.
 *
 * **ALL-OR-NOTHING.** A client either has a whole address or none: the aggregate refuses a half one and
 * `client_address_is_whole` refuses it again at the schema, so this resource is never partially populated. When a
 * client has no address at all, `ClientResource::address` is `null` rather than this object with empty strings.
 */
final readonly class PostalAddressResource
{
    public function __construct(
        /** Street and number. Required whenever an address is present. */
        public string $line1,
        /** A building, a floor, a care-of line. Optional. */
        public ?string $line2,
        /** Postal or ZIP code. Optional, because not every country issues one. */
        public ?string $postcode,
        /** Town or city. Required whenever an address is present. */
        public string $city,
        /** ISO 3166-1 alpha-2, uppercase — `TN`, `FR`, `DE`. */
        public string $countryCode,
    ) {}
}
