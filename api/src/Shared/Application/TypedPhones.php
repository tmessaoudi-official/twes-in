<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\PhoneKept;
use App\Shared\Domain\ValueObject\PhoneVerdict;
use App\Shared\Domain\ValueObject\Refusal;

/**
 * The one rule every record's phone is written under: read by the record's country (« + » or « 00 » naming another),
 * kept as E.164, refused with its country's example when its length is wrong, kept with a doubt when it has the length
 * but lies outside the numbers handed out. What the record already holds is judged only once it changes, so a value
 * kept before the rule, sent back with the rest of a form, is never the reason another change is refused.
 */
final readonly class TypedPhones
{
    public function __construct(private PhoneNumbers $phones)
    {
    }

    /**
     * @param string|null $typed   as the person or the file wrote it; null or blank is no phone
     * @param string|null $held    what the record holds now, null for a record being created
     * @param string|null $country the record's country (ISO 3166-1 alpha-2)
     */
    public function kept(?string $typed, ?string $held, ?string $country): PhoneKept
    {
        $typed = null === $typed ? null : TypedText::trimmed($typed);
        if (null === $typed || '' === $typed || $typed === $held) {
            return new PhoneKept($typed);
        }
        $verdict = $this->phones->judge($typed, $country);

        return match ($verdict->verdict) {
            PhoneVerdict::VALID => new PhoneKept($verdict->e164),
            // The same number written another way, its doubt already said or taken off by whoever looked.
            PhoneVerdict::TO_CHECK => new PhoneKept($verdict->e164, doubt: $verdict->e164 === $held || null === $verdict->e164 ? null : $this->phones->doubt($verdict->e164)),
            PhoneVerdict::WRONG_LENGTH => PhoneKept::refused(new Refusal(
                'phone_length',
                null === $verdict->example
                    ? 'This phone number has too many or too few digits for its country.'
                    : \sprintf('This phone number has too many or too few digits for its country (example: %s).', $verdict->example),
                array_filter(['example' => $verdict->example, 'callingCode' => $verdict->callingCode], static fn (?string $part): bool => null !== $part),
            )),
            PhoneVerdict::NEEDS_COUNTRY => PhoneKept::refused(new Refusal('phone_country', 'With no country to read it by, a phone number starts with + and its country code.')),
            default => PhoneKept::refused(new Refusal('invalid_phone', 'A phone number holds digits, spaces, dots, dashes and brackets, and may start with +.')),
        };
    }
}
