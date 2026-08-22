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
use Twes\Domain\Client\PostalAddress;

/**
 * The `address` object inside the body of `POST /api/clients`. **Input only** — the response carries
 * {@see PostalAddressResource}.
 *
 * **PRESENT OR ABSENT AS A WHOLE.** Send the object with its required parts, or omit `address` entirely. There is
 * no way to send half an address: the aggregate refuses one, and `client_address_is_whole` refuses it again at the
 * schema — a constraint whose firing is proven by a raw INSERT in `DoctrineClientRepositoryTest`, because no route
 * through the domain can produce a row that violates it.
 *
 * The constraints mirror {@see PostalAddress}'s own bounds for the reason {@see NewContactInput} sets out: they are
 * what makes a domain refusal in the handler mean OUR modelling error rather than the caller's.
 */
final readonly class PostalAddressInput
{
    public function __construct(
        /** Street and number. Required. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: PostalAddress::MAX_PART_LENGTH)]
        public string $line1,
        /** Town or city. Required. */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: PostalAddress::MAX_PART_LENGTH)]
        public string $city,
        /**
         * ISO 3166-1 alpha-2, uppercase — `TN`, `FR`, `DE`.
         *
         * **REFUSED RATHER THAN UPCASED**, matching the domain. Silently correcting `fr` to `FR` would mean the
         * value a caller sent and the value stored differ with nothing said, and the day a two-letter code is NOT
         * a country the correction would be applied to it anyway. The schema's `client_country_code_is_alpha_2`
         * says the same thing a third time, at the only level a hand-written INSERT cannot avoid.
         */
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(exactly: 2)]
        #[Assert\Regex(pattern: '/^[A-Z]{2}$/D')]
        public string $countryCode,
        /** A building, a floor, a care-of line. Optional. */
        #[Assert\Length(max: PostalAddress::MAX_PART_LENGTH)]
        public ?string $line2 = null,
        /** Postal or ZIP code. Optional, because not every country issues one. */
        #[Assert\Length(max: PostalAddress::MAX_PART_LENGTH)]
        public ?string $postcode = null,
    ) {}
}
