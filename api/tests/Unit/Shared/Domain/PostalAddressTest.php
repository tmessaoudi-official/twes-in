<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Domain;

use App\Shared\Domain\PostalAddress;
use PHPUnit\Framework\TestCase;

final class PostalAddressTest extends TestCase
{
    public function testAnAddressIsKeptWithoutStraySpacesAndItsCountryInCapitals(): void
    {
        $address = new PostalAddress(' 12, rue du Lac ', '  ', '1053', ' Tunis ', 'tn');

        self::assertSame('12, rue du Lac', $address->line1);
        self::assertNull($address->line2);
        self::assertSame('1053', $address->postalCode);
        self::assertSame('Tunis', $address->city);
        self::assertSame('TN', $address->countryCode);
        self::assertFalse($address->isEmpty());
    }

    public function testAnAddressWithNothingInItIsEmptyAndEqualToAnyOther(): void
    {
        self::assertTrue((new PostalAddress(' ', null, '', null, ' '))->isEmpty());
        self::assertTrue((new PostalAddress())->equals(new PostalAddress('', '', '', '', '')));
        self::assertFalse((new PostalAddress(city: 'Sfax'))->equals(new PostalAddress(city: 'Sousse')));
    }
}
