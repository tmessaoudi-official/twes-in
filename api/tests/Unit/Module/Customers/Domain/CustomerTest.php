<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Customers\Domain;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerGroup;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\InvalidCustomer;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
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
            identifiers: ['matricule_fiscal' => ' 1234567A/B/M/000 ', 'other' => ' '],
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

    private static function profile(?string $email = null): CustomerProfile
    {
        return new CustomerProfile(CustomerKind::Company, 'Carthage Conseil', email: $email, billingAddress: new PostalAddress(city: 'Tunis', countryCode: 'TN'));
    }
}
