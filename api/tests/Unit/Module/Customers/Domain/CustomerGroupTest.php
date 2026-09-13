<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Domain;

use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\InvalidCustomerGroup;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;

final class CustomerGroupTest extends TestCase
{
    public function testAGroupIsNamedAndMayBeDescribed(): void
    {
        $group = CustomerGroup::create(self::company(), ' Grossistes ', '  ', new \DateTimeImmutable());

        self::assertSame('Grossistes', $group->getName());
        self::assertNull($group->getDescription());
    }

    public function testRevisingAGroupToWhatItAlreadySaysChangesNothing(): void
    {
        $group = CustomerGroup::create(self::company(), 'Grossistes', 'Remise 5 %', new \DateTimeImmutable());

        self::assertFalse($group->revise(' Grossistes', 'Remise 5 % ', new \DateTimeImmutable()));
        self::assertTrue($group->revise('Grossistes', null, new \DateTimeImmutable()));
        self::assertNull($group->getDescription());
    }

    public function testAGroupWithoutANameIsRefused(): void
    {
        $this->expectException(InvalidCustomerGroup::class);

        CustomerGroup::create(self::company(), '   ', null, new \DateTimeImmutable());
    }

    public function testADescriptionLongerThanFiveHundredCharactersIsRefused(): void
    {
        try {
            CustomerGroup::create(self::company(), 'Grossistes', str_repeat('a', 501), new \DateTimeImmutable());
            self::fail('A 501-character description was accepted.');
        } catch (InvalidCustomerGroup $refused) {
            self::assertSame('description', $refused->field);
        }
    }

    private static function company(): Company
    {
        return new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
    }
}
