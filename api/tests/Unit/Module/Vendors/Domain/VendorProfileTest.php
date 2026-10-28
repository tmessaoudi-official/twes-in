<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Vendors\Domain;

use App\Module\Vendors\Domain\Exception\InvalidVendor;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Shared\Domain\Model\PostalAddress;
use PHPUnit\Framework\TestCase;

final class VendorProfileTest extends TestCase
{
    public function testBankCodesAndRegistrationNumbersAreKeptUppercaseWithoutSpacesAndBlanksAreNoValue(): void
    {
        $profile = new VendorProfile(' Sotumag ', '  ', ['matricule_fiscal' => ' ', 'other' => ' 1 ', 'siren' => "552\u{00A0}100 554"], iban: 'tn59 1000 6035 1835 9847 8831', bic: ' stbk tntt ', notes: '');

        self::assertSame('Sotumag', $profile->name);
        self::assertNull($profile->legalName);
        self::assertSame(['other' => '1', 'siren' => '552100554'], $profile->identifiers, 'a registration number kept without its spaces');
        self::assertSame('TN5910006035183598478831', $profile->iban);
        self::assertSame('STBKTNTT', $profile->bic);
        self::assertNull($profile->notes);
        self::assertNull((new VendorProfile('Sotumag', iban: ' ', bic: ''))->iban);
        // As copied from a printed RIB or a hint, its groups kept apart by a no-break space.
        $copied = new VendorProfile('Sotumag', iban: "TN59\u{202F}1000\u{202F}6035\u{202F}1835\u{202F}9847\u{202F}8831", bic: "STBK\u{00A0}TN\u{00A0}TT");
        self::assertSame('TN5910006035183598478831', $copied->iban);
        self::assertSame('STBKTNTT', $copied->bic);
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
                self::assertSame(['paymentTermsDays', 'invalid_payment_terms'], [$refused->field, $refused->reason]);
            }
        }
    }

    public function testAVendorIsNamed(): void
    {
        try {
            new VendorProfile('   ');
            self::fail('a vendor with no name was accepted');
        } catch (InvalidVendor $refused) {
            self::assertSame(['name', 'A vendor is named in 1 to 200 characters.'], [$refused->field, $refused->getMessage()]);
            self::assertSame(['invalid_text', ['min' => 1, 'max' => VendorProfile::NAME_MAX]], [$refused->reason, $refused->params], 'said as the import says a text it refuses');
        }
    }

    public function testDifferencesNameTheFieldsInDeclarationOrder(): void
    {
        $before = new VendorProfile('Sotumag', email: 'a@sotumag.tn', address: new PostalAddress('Zone', city: 'Tunis'));
        $after = new VendorProfile('Sotumag', email: 'b@sotumag.tn', address: new PostalAddress('Zone', city: 'Sfax'), paymentTermsDays: 30);

        self::assertSame(['email', 'address', 'paymentTermsDays'], $after->differencesFrom($before));
        self::assertSame([], $before->differencesFrom(new VendorProfile(' Sotumag', email: 'a@sotumag.tn ', address: new PostalAddress('Zone ', city: 'Tunis'))));
    }
}
