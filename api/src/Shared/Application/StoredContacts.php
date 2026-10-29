<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Application\Phones\TypedPhones;
use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Refusal;

/**
 * What a record keeps that today's rules for an address and a phone would refuse, for the nightly checks and marks
 * (row 208): a value kept before a rule stays until it is changed, and these find it so a person corrects it. A
 * national number that the plan reads is not refused, only not yet written in its international form; one of the right
 * length outside the ranges its country hands out follows the platform's policy, refused, or kept with a doubt.
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

    /**
     * What a record with no mark of its own says « À vérifier » of its contact (the company's profile, an establishment):
     * each field today's rules refuse, and `phone_outside_ranges` for a phone outside the ranges its country hands out
     * that the platform keeps rather than refuses, which nothing else would say (never silent). It stays until the
     * number changes: such a record keeps no trace of someone having looked.
     *
     * @param string|null $country the record's country, which reads a national number
     *
     * @return list<'email'|'phone'|'phone_outside_ranges'>
     */
    public function toCheck(?string $email, ?string $phone, ?string $country): array
    {
        $fields = array_keys($this->refused($email, $phone, $country));
        if (!\in_array('phone', $fields, true) && null !== $this->phones->kept($phone, null, $country)->doubt) {
            $fields[] = 'phone_outside_ranges';
        }

        return $fields;
    }
}
