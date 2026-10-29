<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Refusal;

/**
 * What a record keeps that today's rules for an address and a phone would refuse, for the nightly checks and marks
 * (row 208): a value kept before a rule stays until it is changed, and these find it so a person corrects it. A
 * national number that the plan reads is not refused, only not yet written in its international form, nor is one of
 * the right length the plan only reads to check.
 */
final readonly class StoredContacts
{
    public function __construct(private TypedPhones $phones)
    {
    }

    /**
     * @param string|null $country the record's country, which reads a national number
     *
     * @return array<'email'|'phone', Refusal> what today's rule says of each field it refuses
     */
    public function refused(?string $email, ?string $phone, ?string $country): array
    {
        $refused = [];
        $email = null === $email ? '' : TypedText::trimmed($email);
        $byEmail = '' === $email ? null : ContactRules::email($email);
        if (null !== $byEmail) {
            $refused['email'] = $byEmail;
        }
        $byPhone = $this->phones->kept($phone, null, $country)->refusal;
        if (null !== $byPhone) {
            $refused['phone'] = $byPhone;
        }

        return $refused;
    }
}
