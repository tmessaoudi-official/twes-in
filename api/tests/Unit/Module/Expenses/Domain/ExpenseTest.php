<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Expenses\Domain;

use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseCategory;
use App\Module\Expenses\Domain\ExpenseDetails;
use App\Module\Expenses\Domain\ExpenseStatus;
use App\Module\Expenses\Domain\ExpenseTransitionRefused;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use PHPUnit\Framework\TestCase;

final class ExpenseTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Company $acme;
    private TaxComponent $vat19;
    private ExpenseCategory $fuel;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->vat19 = $this->percentage($this->acme, 'TVA19', '19', 3);
        $this->fuel = ExpenseCategory::create($this->acme, 'Carburant', null, $this->now);
    }

    public function testTheTaxIsTheRateOnTheNetRoundedHalfAwayFromZeroAtTheCurrencysScale(): void
    {
        $expense = Expense::create($this->acme, $this->details('100.005'), null, $this->fuel, $this->vat19, 3, $this->now);

        self::assertSame(['100.005', '19.000', '19.001', '119.006', 'TND'], [$expense->getAmountNet(), $expense->getTaxRate(), $expense->getTaxAmount(), $expense->getAmountGross(), $expense->getCurrency()]);

        $globex = new Company('Globex', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $vat5 = $this->percentage($globex, 'TVA5', '5', 2);
        $tie = Expense::create($globex, $this->details('0.10'), null, null, $vat5, 2, $this->now);
        self::assertSame(['0.100', '0.010', '0.110', 'EUR'], [$tie->getAmountNet(), $tie->getTaxAmount(), $tie->getAmountGross(), $tie->getCurrency()], '0.005 is a tie, rounded away from zero');
    }

    public function testWithoutATaxTheGrossIsTheNet(): void
    {
        $expense = Expense::create($this->acme, $this->details('42.5'), null, null, null, 3, $this->now);

        self::assertSame(['42.500', null, '0.000', '42.500'], [$expense->getAmountNet(), $expense->getTaxRate(), $expense->getTaxAmount(), $expense->getAmountGross()]);
        self::assertSame(ExpenseStatus::Draft, $expense->getStatus());
    }

    public function testANetOfZeroOrFinerThanTheCurrencyIsRefused(): void
    {
        foreach (['0', '0.000', '-3', '1.2345', 'ten'] as $net) {
            $this->assertRefused('amountNet', fn () => $this->details($net), $net);
        }
        $this->assertRefused('amountNet', fn () => Expense::create($this->acme, $this->details('10.005'), null, null, null, 2, $this->now), 'a euro has two decimals');
    }

    public function testOnlyAnActiveRateOnTheNetOfTheSameCompanyTaxesAnExpense(): void
    {
        $stamp = TaxComponent::create($this->acme, 'TIMBRE', 'Timbre', TaxFamily::Stamp, null, '1.000', null, false, false, null, 0, 3, $this->now);
        $this->assertRefused('taxComponentId', fn () => Expense::create($this->acme, $this->details('10'), null, null, $stamp, 3, $this->now), 'a stamp');

        $theirs = $this->percentage(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'TVA19', '19', 3);
        $this->assertRefused('taxComponentId', fn () => Expense::create($this->acme, $this->details('10'), null, null, $theirs, 3, $this->now), 'another company’s');

        $this->vat19->revise('TVA 19 %', '19', null, null, false, false, false, null, 0, 3, $this->now);
        $this->assertRefused('taxComponentId', fn () => Expense::create($this->acme, $this->details('10'), null, null, $this->vat19, 3, $this->now), 'an inactive one');
    }

    public function testTheVendorAndTheCategoryAreTheCompanysAndActive(): void
    {
        $theirVendor = Vendor::create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'FRN-0001', new VendorProfile('Autre'), $this->now);
        $this->assertRefused('vendorId', fn () => Expense::create($this->acme, $this->details('10'), $theirVendor, null, null, 3, $this->now), 'another company’s vendor');

        $retired = Vendor::create($this->acme, 'FRN-0002', new VendorProfile('Ancien'), $this->now);
        $retired->revise('FRN-0002', new VendorProfile('Ancien'), false, $this->now);
        $this->assertRefused('vendorId', fn () => Expense::create($this->acme, $this->details('10'), $retired, null, null, 3, $this->now), 'an inactive vendor');

        $closed = ExpenseCategory::create($this->acme, 'Ancienne', null, $this->now);
        $closed->revise('Ancienne', null, false, $this->now);
        $this->assertRefused('categoryId', fn () => Expense::create($this->acme, $this->details('10'), null, $closed, null, 3, $this->now), 'an inactive category');
    }

    public function testADraftIsRevisedThenRecordedOnceItHasACategoryAndNeverRevisedAgain(): void
    {
        $expense = Expense::create($this->acme, $this->details('10'), null, null, null, 3, $this->now);

        $this->assertRefused('categoryId', fn () => $expense->record($this->now), 'an expense is recorded under a category');

        self::assertSame(['amountNet', 'categoryId', 'taxComponentId'], $expense->revise($this->details('20'), null, $this->fuel, $this->vat19, 3, $this->now));
        self::assertSame([], $expense->revise($this->details('20'), null, $this->fuel, $this->vat19, 3, $this->now));
        self::assertSame(['23.800'], [$expense->getAmountGross()]);

        $expense->record($this->now);
        self::assertSame(ExpenseStatus::Recorded, $expense->getStatus());

        $this->expectException(ExpenseTransitionRefused::class);
        $expense->revise($this->details('30'), null, $this->fuel, null, 3, $this->now);
    }

    public function testARecordedExpenseIsPaidOnADayFromItsDateToToday(): void
    {
        $expense = Expense::create($this->acme, $this->details('10'), null, $this->fuel, null, 3, $this->now);
        $today = new \DateTimeImmutable('2026-09-15');

        try {
            $expense->pay(PaymentMethod::Cash, new \DateTimeImmutable('2026-09-12'), $today, $this->now);
            self::fail('a draft was paid');
        } catch (ExpenseTransitionRefused) {
        }
        $expense->record($this->now);

        $this->assertRefused('paidOn', fn () => $expense->pay(PaymentMethod::Cash, new \DateTimeImmutable('2026-09-09'), $today, $this->now), 'before the expense');
        $this->assertRefused('paidOn', fn () => $expense->pay(PaymentMethod::Cash, new \DateTimeImmutable('2026-09-16'), $today, $this->now), 'after today');

        $expense->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), $today, $this->now);
        self::assertSame([ExpenseStatus::Paid, PaymentMethod::Transfer, '2026-09-12'], [$expense->getStatus(), $expense->getPaymentMethod(), $expense->getPaidOn()?->format('Y-m-d')]);

        $this->expectException(ExpenseTransitionRefused::class);
        $expense->pay(PaymentMethod::Cash, new \DateTimeImmutable('2026-09-13'), $today, $this->now);
    }

    public function testTheDueDayIsTheVendorsPaymentTermsAfterTheExpensesDate(): void
    {
        $sotumag = Vendor::create($this->acme, 'FRN-0001', new VendorProfile('Sotumag', paymentTermsDays: 30), $this->now);
        $cash = Vendor::create($this->acme, 'FRN-0002', new VendorProfile('Kiosque'), $this->now);

        self::assertSame('2026-10-10', Expense::create($this->acme, $this->details('10'), $sotumag, null, null, 3, $this->now)->getDueDate()?->format('Y-m-d'));
        self::assertNull(Expense::create($this->acme, $this->details('10'), $cash, null, null, 3, $this->now)->getDueDate(), 'a vendor without terms');
        self::assertNull(Expense::create($this->acme, $this->details('10'), null, null, null, 3, $this->now)->getDueDate(), 'no vendor');
    }

    public function testACategoryNeverSitsUnderItselfOrOneOfItsOwnSubcategories(): void
    {
        $vehicles = ExpenseCategory::create($this->acme, 'Véhicules', null, $this->now);
        self::assertSame(['parentId'], $this->fuel->revise('Carburant', $vehicles, true, $this->now));

        foreach (['itself' => $vehicles, 'its subcategory' => $this->fuel] as $case => $parent) {
            try {
                $vehicles->revise('Véhicules', $parent, true, $this->now);
                self::fail("a category sat under $case");
            } catch (InvalidExpense $refused) {
                self::assertSame('parentId', $refused->field, $case);
            }
        }
        $this->assertRefused('name', fn () => ExpenseCategory::create($this->acme, '  ', null, $this->now), 'an empty name');
    }

    private function details(string $net): ExpenseDetails
    {
        return new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Gasoil septembre', $net, 'F-2026-118');
    }

    private function percentage(Company $company, string $code, string $rate, int $scale): TaxComponent
    {
        return TaxComponent::create($company, $code, $code, TaxFamily::Vat, $rate, null, null, false, false, null, 0, $scale, $this->now);
    }

    /** @param \Closure(): mixed $attempt */
    private function assertRefused(string $field, \Closure $attempt, string $case): void
    {
        try {
            $attempt();
            self::fail("$case was accepted");
        } catch (InvalidExpense $refused) {
            self::assertSame($field, $refused->field, $case);
        }
    }
}
