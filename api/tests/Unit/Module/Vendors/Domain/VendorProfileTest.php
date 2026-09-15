<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Vendors\Domain;

use App\Module\Vendors\Domain\InvalidVendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Shared\Domain\PostalAddress;
use PHPUnit\Framework\TestCase;

final class VendorProfileTest extends TestCase
{
    public function testBankCodesAreKeptUppercaseWithoutSpacesAndBlanksAreNoValue(): void
    {
        $profile = new VendorProfile(' Sotumag ', '  ', ['matricule_fiscal' => ' ', 'other' => ' 1 '], iban: 'tn59 1000 6035 1835 9847 8831', bic: ' stbk tntt ', notes: '');

        self::assertSame('Sotumag', $profile->name);
        self::assertNull($profile->legalName);
        self::assertSame(['other' => '1'], $profile->identifiers);
        self::assertSame('TN5910006035183598478831', $profile->iban);
        self::assertSame('STBKTNTT', $profile->bic);
        self::assertNull($profile->notes);
        self::assertNull((new VendorProfile('Sotumag', iban: ' ', bic: ''))->iban);
    }

    public function testPaymentTermsRunFromZeroToAYear(): void
    {
        self::assertSame(0, (new VendorProfile('Sotumag', paymentTermsDays: 0))->paymentTermsDays);
        self::assertSame(365, (new VendorProfile('Sotumag', paymentTermsDays: 365))->paymentTermsDays);

        foreach ([-1, 366] as $days) {
            try {
                new VendorProfile('Sotumag', paymentTermsDays: $days);
                self::fail("$days days were accepted");
            } catch (InvalidVendor $refused) {
                self::assertSame('paymentTermsDays', $refused->field);
            }
        }
    }

    public function testAVendorIsNamed(): void
    {
        $this->expectExceptionObject(new InvalidVendor('name', 'A vendor is named in 1 to 200 characters.'));

        new VendorProfile('   ');
    }

    public function testDifferencesNameTheFieldsInDeclarationOrder(): void
    {
        $before = new VendorProfile('Sotumag', email: 'a@sotumag.tn', address: new PostalAddress('Zone', city: 'Tunis'));
        $after = new VendorProfile('Sotumag', email: 'b@sotumag.tn', address: new PostalAddress('Zone', city: 'Sfax'), paymentTermsDays: 30);

        self::assertSame(['email', 'address', 'paymentTermsDays'], $after->differencesFrom($before));
        self::assertSame([], $before->differencesFrom(new VendorProfile(' Sotumag', email: 'a@sotumag.tn ', address: new PostalAddress('Zone ', city: 'Tunis'))));
    }
}
