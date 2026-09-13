<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Domain;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Module\Customers\Domain\Contact;
use App\Module\Customers\Domain\ContactDetails;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\InvalidContact;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;

final class ContactTest extends TestCase
{
    public function testAContactIsSomeoneAtTheCustomerWithAFirstOrALastName(): void
    {
        $contact = Contact::create(self::customer(), new ContactDetails(' Leila ', ' ', ' leila@carthage.tn ', null, ' Comptable '), true, new \DateTimeImmutable());

        self::assertSame('Leila', $contact->getDetails()->firstName);
        self::assertNull($contact->getDetails()->lastName);
        self::assertSame('leila@carthage.tn', $contact->getDetails()->email);
        self::assertSame('Comptable', $contact->getDetails()->role);
        self::assertTrue($contact->isPrimary());
    }

    public function testAContactWithNeitherNameIsRefused(): void
    {
        try {
            new ContactDetails(' ', null, 'leila@carthage.tn', null, null);
            self::fail('A nameless contact was accepted.');
        } catch (InvalidContact $refused) {
            self::assertSame('lastName', $refused->field);
        }
    }

    public function testRevisingAContactToWhatItSaysChangesNothing(): void
    {
        $contact = Contact::create(self::customer(), new ContactDetails('Leila', 'Ben Salah', null, null, null), false, new \DateTimeImmutable());

        self::assertFalse($contact->revise(new ContactDetails(' Leila', 'Ben Salah ', '', null, null), new \DateTimeImmutable()));
        self::assertTrue($contact->revise(new ContactDetails('Leila', 'Trabelsi', null, null, null), new \DateTimeImmutable()));
        self::assertFalse($contact->markPrimary(false, new \DateTimeImmutable()));
        self::assertTrue($contact->markPrimary(true, new \DateTimeImmutable()));
    }

    private static function customer(): Customer
    {
        $now = new \DateTimeImmutable();

        return Customer::create(
            new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'),
            'CLI-0001',
            new CustomerProfile(CustomerKind::Company, 'Carthage Conseil'),
            null,
            new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $now),
            [],
            $now,
        );
    }
}
