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
use App\Shared\Domain\PostalAddress;
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

    /** @return iterable<string, array{VendorProfile, string, string, array<string, string|int>}> */
    public static function refusedProfiles(): iterable
    {
        yield 'an e-mail' => [new VendorProfile('Sotumag', email: 'a@b'), 'email', 'invalid_email', []];
        yield 'a phone' => [new VendorProfile('Sotumag', phone: '12'), 'phone', 'invalid_phone', []];
        yield 'a website' => [new VendorProfile('Sotumag', website: 'ftp://sotumag.tn'), 'website', 'invalid_website', []];
        yield 'a legal name' => [new VendorProfile('Sotumag', legalName: str_repeat('x', 201)), 'legalName', 'invalid_legal_name', []];
        yield 'an IBAN' => [new VendorProfile('Sotumag', iban: 'TN5910006035183598478832'), 'iban', 'invalid_iban', []];
        yield 'a BIC' => [new VendorProfile('Sotumag', bic: 'BIATZZTT'), 'bic', 'invalid_bic', []];
        yield 'notes' => [new VendorProfile('Sotumag', notes: str_repeat('x', 5001)), 'notes', 'invalid_text', ['max' => 5000]];
        yield 'an address line' => [new VendorProfile('Sotumag', address: new PostalAddress(str_repeat('x', 201))), 'addressLine1', 'invalid_text', ['max' => 200]];
        yield 'a city' => [new VendorProfile('Sotumag', address: new PostalAddress(city: str_repeat('x', 121))), 'city', 'invalid_text', ['max' => 120]];
        yield 'a country' => [new VendorProfile('Sotumag', address: new PostalAddress(countryCode: 'XX')), 'countryCode', 'invalid_country', []];
    }

    /**
     * What the form holds a vendor to, the domain holds every writer to, under the form field it names.
     *
     * @param array<string, string|int> $params
     */
    #[DataProvider('refusedProfiles')]
    public function testAProfileIsRefusedWhereverItIsWrittenUnderTheFieldItNames(VendorProfile $profile, string $field, string $reason, array $params): void
    {
        $company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $now = new \DateTimeImmutable('2026-10-15 10:40:00');
        foreach (['created' => static fn () => Vendor::create($company, 'FRN-001', $profile, $now), 'revised' => static fn () => Vendor::create($company, 'FRN-001', new VendorProfile('Sotumag'), $now)->revise('FRN-001', $profile, true, $now)] as $how => $write) {
            try {
                $write();
                self::fail("$field accepted when $how");
            } catch (InvalidVendor $refused) {
                self::assertSame([$field, $reason, $params], [$refused->field, $refused->reason, $refused->params], $how);
            }
        }
    }

    public function testAVendorStoredBeforeARuleStillReads(): void
    {
        $vendor = Vendor::create(new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'FRN-001', new VendorProfile('Sotumag'), new \DateTimeImmutable('2026-10-15 10:40:00'));
        new \ReflectionProperty(Vendor::class, 'iban')->setValue($vendor, 'TN00');

        self::assertSame('TN00', $vendor->getProfile()->iban);
    }
}
