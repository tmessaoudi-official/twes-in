<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

use App\Shared\Domain\Service\ContactRules;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\PhoneVerdict;

/**
 * What a record keeps that today's rules for an address and a phone would refuse, for the nightly checks (row 208): a
 * value kept before a rule stays until it is changed, and these find it so a person corrects it. A national number
 * that the plan reads is not refused, only not yet written in its international form.
 */
final readonly class StoredContacts
{
    public function __construct(private PhoneNumbers $phones)
    {
    }

    /**
     * @param string|null $country the record's country, which reads a national number
     *
     * @return list<'email'|'phone'> the fields whose stored value today's rule refuses
     */
    public function refused(?string $email, ?string $phone, ?string $country): array
    {
        $refused = [];
        $email = null === $email ? '' : TypedText::trimmed($email);
        if ('' !== $email && null !== ContactRules::email($email)) {
            $refused[] = 'email';
        }
        $phone = null === $phone ? '' : TypedText::trimmed($phone);
        if ('' !== $phone && !\in_array($this->phones->judge($phone, $country)->verdict, [PhoneVerdict::VALID, PhoneVerdict::TO_CHECK], true)) {
            $refused[] = 'phone';
        }

        return $refused;
    }
}
