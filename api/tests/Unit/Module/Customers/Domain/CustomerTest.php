<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Domain;

use App\Fiscal\Domain\Model\CustomerTaxRegime;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Module\Customers\Domain\Exception\InvalidCustomer;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\Model\CustomerGroup;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Shared\Domain\Model\PostalAddress;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class CustomerTest extends TestCase
{
    private Company $company;
    private CustomerTaxRegime $standard;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-14 09:00:00');
        $this->company = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->standard = new CustomerTaxRegime('TN', 'standard', 'fiscal.regime.standard', [], null, 0, $this->now);
    }

    public function testACustomerIsCreatedWithItsNumberProfileRegimeAndDefaultTaxes(): void
    {
        $tax = Uuid::v7();
        $customer = Customer::create($this->company, ' CLI-0001 ', self::profile(), null, $this->standard, [$tax, $tax], $this->now);

        self::assertSame('CLI-0001', $customer->getNumber());
        self::assertSame('Carthage Conseil', $customer->getProfile()->name);
        self::assertSame($this->standard, $customer->getTaxRegime());
        self::assertSame([$tax->toRfc4122()], $customer->getDefaultTaxComponentIds());
        self::assertTrue($customer->isActive());
        self::assertNull($customer->getGroup());
    }

    public function testAProfileKeepsNoStraySpacesAndItsDiscountRateAtThreeDecimals(): void
    {
        $profile = new CustomerProfile(
            kind: CustomerKind::Company,
            name: ' Carthage Conseil ',
            legalName: ' ',
            // As a spreadsheet or a person writes it: spaced out, a no-break space among them, in lower case.
            identifiers: ['matricule_fiscal' => " 1234567\u{00A0}a / b / m\u{202F}/ 000 ", 'other' => ' '],
            billingAddress: new PostalAddress(city: ' Tunis ', countryCode: 'tn'),
            shippingAddress: new PostalAddress(' ', null, null, null, null),
            defaultDiscountRate: '5.5',
            notes: '  ',
        );

        self::assertSame('Carthage Conseil', $profile->name);
        self::assertNull($profile->legalName);
        self::assertSame(['matricule_fiscal' => '1234567A/B/M/000'], $profile->identifiers);
        self::assertSame('TN', $profile->billingAddress->countryCode);
        self::assertNull($profile->shippingAddress, 'an empty shipping address means the billing address');
        self::assertSame('5.500', $profile->defaultDiscountRate);
        self::assertNull($profile->notes);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedRates(): iterable
    {
        yield 'above a hundred' => ['100.001'];
        yield 'negative' => ['-1'];
        yield 'finer than a rate column holds' => ['5.0001'];
        yield 'not a number' => ['cinq'];
    }

    #[DataProvider('refusedRates')]
    public function testADiscountRateOutsideZeroToAHundredIsRefused(string $rate): void
    {
        try {
            new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', defaultDiscountRate: $rate);
            self::fail("A discount rate of $rate was accepted.");
        } catch (InvalidCustomer $refused) {
            self::assertSame('defaultDiscountRate', $refused->field);
        }
    }

    public function testACustomerWithoutANameIsRefused(): void
    {
        try {
            new CustomerProfile(CustomerKind::Individual, '  ');
            self::fail('A nameless customer was accepted.');
        } catch (InvalidCustomer $refused) {
            self::assertSame('name', $refused->field);
            // Said as the import says a text it refuses, under its maximum.
            self::assertSame(['invalid_text', ['min' => 1, 'max' => CustomerProfile::NAME_MAX]], [$refused->reason, $refused->params]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function refusedNumbers(): iterable
    {
        yield 'blank' => ['  '];
        yield 'with a space inside' => ['CLI 001'];
        yield 'longer than 32 characters' => [str_repeat('9', 33)];
    }

    #[DataProvider('refusedNumbers')]
    public function testANumberIsLettersDigitsAndSeparators(string $number): void
    {
        try {
            Customer::create($this->company, $number, self::profile(), null, $this->standard, [], $this->now);
            self::fail("The number \"$number\" was accepted.");
        } catch (InvalidCustomer $refused) {
            self::assertSame('number', $refused->field);
            self::assertSame('invalid_number', $refused->reason, 'said as the import says a number it refuses');
        }
    }

    public function testARegimeFromAnotherCountrysPresetIsRefused(): void
    {
        $french = new CustomerTaxRegime('FR', 'standard', 'fiscal.regime.standard', [TaxFamily::Vat], null, 0, $this->now);

        try {
            Customer::create($this->company, 'CLI-0001', self::profile(), null, $french, [], $this->now);
            self::fail("A French regime was given to a Tunisian company's customer.");
        } catch (InvalidCustomer $refused) {
            self::assertSame('taxRegime', $refused->field);
        }
    }

    public function testAGroupOfAnotherCompanyIsRefused(): void
    {
        $theirs = CustomerGroup::create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'Grossistes', null, $this->now);

        try {
            Customer::create($this->company, 'CLI-0001', self::profile(), $theirs, $this->standard, [], $this->now);
            self::fail("Another company's group was accepted.");
        } catch (InvalidCustomer $refused) {
            self::assertSame('customerGroupId', $refused->field);
        }
    }

    public function testARevisionNamesTheFieldsItChangedAndNothingWhenNothingChanged(): void
    {
        $customer = Customer::create($this->company, 'CLI-0001', self::profile(), null, $this->standard, [], $this->now);
        $group = CustomerGroup::create($this->company, 'Grossistes', null, $this->now);
        $exempt = new CustomerTaxRegime('TN', 'exempt', 'fiscal.regime.exempt', [TaxFamily::Vat], null, 1, $this->now);

        self::assertSame([], $customer->revise('CLI-0001', self::profile(), null, $this->standard, [], true, $this->now));

        $changed = $customer->revise('CLI-0002', self::profile(email: 'compta@carthage.tn'), $group, $exempt, [], false, $this->now);

        self::assertSame(['number', 'email', 'customerGroupId', 'taxRegime', 'isActive'], $changed);
        self::assertSame($group, $customer->getGroup());
        self::assertFalse($customer->isActive());
    }

    /** @return iterable<string, array{CustomerProfile, string, string, array<string, string|int>}> */
    public static function refusedProfiles(): iterable
    {
        $at = static fn (PostalAddress $billing, ?PostalAddress $shipping = null): CustomerProfile => new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', billingAddress: $billing, shippingAddress: $shipping);
        yield 'an e-mail' => [self::profile('ali@'), 'email', 'invalid_email', []];
        yield 'a phone' => [new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', phone: 'abc'), 'phone', 'invalid_phone', []];
        yield 'a website' => [new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', website: 'javascript:alert(1)'), 'website', 'invalid_website', []];
        yield 'a legal name' => [new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', legalName: str_repeat('x', 201)), 'legalName', 'invalid_legal_name', []];
        yield 'notes' => [new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', notes: str_repeat('x', 5001)), 'notes', 'invalid_text', ['max' => 5000]];
        yield 'a billing line' => [$at(new PostalAddress(str_repeat('x', 201))), 'billingAddressLine1', 'invalid_text', ['max' => 200]];
        yield 'a billing postal code' => [$at(new PostalAddress(postalCode: str_repeat('1', 21))), 'billingPostalCode', 'invalid_text', ['max' => 20]];
        yield 'a billing city' => [$at(new PostalAddress(city: str_repeat('x', 121))), 'billingCity', 'invalid_text', ['max' => 120]];
        yield 'a billing country' => [$at(new PostalAddress(countryCode: 'xx')), 'billingCountryCode', 'invalid_country', []];
        yield 'a shipping line' => [$at(new PostalAddress(city: 'Tunis'), new PostalAddress(null, str_repeat('x', 201))), 'shippingAddressLine2', 'invalid_text', ['max' => 200]];
        yield 'a shipping country' => [$at(new PostalAddress(city: 'Tunis'), new PostalAddress(city: 'Paris', countryCode: 'FX')), 'shippingCountryCode', 'invalid_country', []];
    }

    /**
     * What the form holds a customer to, the domain holds every writer to, under the form field it names and the reason a
     * screen or a file's report translates (docs/SPEC.md § 7, 2026-10-09 10:40 (1)).
     *
     * @param array<string, string|int> $params
     */
    #[DataProvider('refusedProfiles')]
    public function testAProfileIsRefusedWhereverItIsWrittenUnderTheFieldItNames(CustomerProfile $profile, string $field, string $reason, array $params): void
    {
        foreach (['created' => fn () => Customer::create($this->company, 'CLI-0001', $profile, null, $this->standard, [], $this->now), 'revised' => fn () => Customer::create($this->company, 'CLI-0001', self::profile(), null, $this->standard, [], $this->now)->revise('CLI-0001', $profile, null, $this->standard, [], true, $this->now)] as $how => $write) {
            try {
                $write();
                self::fail("$field accepted when $how");
            } catch (InvalidCustomer $refused) {
                self::assertSame([$field, $reason, $params], [$refused->field, $refused->reason, $refused->params], $how);
            }
        }
    }

    /** A rule holds what is written from now on: a row stored before it still reads, and is refused only if written again. */
    public function testACustomerStoredBeforeARuleStillReads(): void
    {
        $customer = Customer::create($this->company, 'CLI-0001', self::profile('ali@exemple.tn'), null, $this->standard, [], $this->now);
        new \ReflectionProperty(Customer::class, 'email')->setValue($customer, 'ali@');

        $profile = $customer->getProfile();

        self::assertSame('ali@', $profile->email);
        self::assertSame([], $customer->revise('CLI-0001', $profile, null, $this->standard, [], true, $this->now), 'saying again what is stored writes nothing');
        // Row 208: the address kept before the rule never refuses another change, only a change of its own.
        self::assertSame(['number'], $customer->revise('CLI-0002', $profile, null, $this->standard, [], true, $this->now));
        $this->expectException(InvalidCustomer::class);
        $customer->revise('CLI-0002', self::profile('ali@x'), null, $this->standard, [], true, $this->now);
    }

    private static function profile(?string $email = null): CustomerProfile
    {
        return new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', email: $email, billingAddress: new PostalAddress(city: 'Tunis', countryCode: 'TN'));
    }
}
