<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Domain;

/** Who a contact is and how to reach them; kept without stray spaces, with a first or a last name at least. */
final readonly class ContactDetails
{
    public const int NAME_MAX = 100;

    public ?string $firstName;
    public ?string $lastName;
    public ?string $email;
    public ?string $phone;
    /** What they do at the customer: "Comptable", "Purchasing". */
    public ?string $role;

    /** @throws InvalidContact */
    public function __construct(?string $firstName, ?string $lastName, ?string $email, ?string $phone, ?string $role)
    {
        $this->firstName = self::text($firstName, 'firstName');
        $this->lastName = self::text($lastName, 'lastName');
        if (null === $this->firstName && null === $this->lastName) {
            throw new InvalidContact('lastName', 'A contact has a first or a last name.');
        }
        $this->email = self::text($email, 'email');
        $this->phone = self::text($phone, 'phone');
        $this->role = self::text($role, 'role');
    }

    public function equals(self $other): bool
    {
        return get_object_vars($this) === get_object_vars($other);
    }

    private static function text(?string $value, string $field): ?string
    {
        $value = null === $value ? '' : trim($value);
        if (\in_array($field, ['firstName', 'lastName', 'role'], true) && mb_strlen($value) > self::NAME_MAX) {
            throw new InvalidContact($field, \sprintf('At most %d characters.', self::NAME_MAX));
        }

        return '' === $value ? null : $value;
    }
}
