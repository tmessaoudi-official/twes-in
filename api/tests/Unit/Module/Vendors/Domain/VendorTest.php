<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Vendors\Domain;

use App\Module\Vendors\Domain\InvalidVendor;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VendorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function refusedNumbers(): iterable
    {
        yield 'blank' => ['  '];
        yield 'with a space inside' => ['FRN 001'];
        yield 'longer than 32 characters' => [str_repeat('9', 33)];
    }

    #[DataProvider('refusedNumbers')]
    public function testANumberIsLettersDigitsAndSeparators(string $number): void
    {
        try {
            Vendor::create(new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'), $number, new VendorProfile('Sotumag'), new \DateTimeImmutable('2026-10-15 10:40:00'));
            self::fail("The number \"$number\" was accepted.");
        } catch (InvalidVendor $refused) {
            self::assertSame(['number', 'invalid_number'], [$refused->field, $refused->reason], 'said as the import says a number it refuses');
        }
    }
}
