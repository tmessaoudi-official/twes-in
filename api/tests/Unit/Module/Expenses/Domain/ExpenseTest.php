<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Expenses\Domain;

use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxFamily;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseAmounts;
use App\Module\Expenses\Domain\ExpenseCategory;
use App\Module\Expenses\Domain\ExpenseDetails;
use App\Module\Expenses\Domain\ExpenseStatus;
use App\Module\Expenses\Domain\ExpenseTransitionRefused;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Module\Expenses\Domain\TejOperationCode;
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
        $expense = $this->create($this->acme, '100.005', null, $this->fuel, $this->vat19, 3);

        self::assertSame(['100.005', '19.000', '19.001', '119.006', 'TND'], [$expense->getAmountNet(), $expense->getTaxes()[0]->getRate(), $expense->getTaxAmount(), $expense->getAmountGross(), $expense->getCurrency()]);

        $globex = new Company('Globex', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $vat5 = $this->percentage($globex, 'TVA5', '5', 2);
        $tie = $this->create($globex, '0.10', null, null, $vat5, 2);
        self::assertSame(['0.100', '0.010', '0.110', 'EUR'], [$tie->getAmountNet(), $tie->getTaxAmount(), $tie->getAmountGross(), $tie->getCurrency()], '0.005 is a tie, rounded away from zero');
    }

    public function testWithoutATaxTheGrossIsTheNet(): void
    {
        $expense = $this->create($this->acme, '42.5', null, null, null, 3);

        self::assertSame(['42.500', [], '0.000', '42.500'], [$expense->getAmountNet(), $expense->getTaxes(), $expense->getTaxAmount(), $expense->getAmountGross()]);
        self::assertSame(ExpenseStatus::Draft, $expense->getStatus());
    }

    public function testANetOfZeroOrFinerThanTheCurrencyIsRefused(): void
    {
        foreach (['0', '0.000', '-3', '1.2345', 'ten'] as $net) {
            $this->assertRefused('lines[0].amountNet', static fn () => ExpenseAmounts::one($net, 3), $net);
        }
        $this->assertRefused('lines[0].amountNet', fn () => $this->create($this->acme, '10.005', null, null, null, 2), 'a euro has two decimals');
    }

    public function testOnlyAnActiveRateOnTheNetOfTheSameCompanyTaxesAnExpense(): void
    {
        $stamp = TaxComponent::create($this->acme, 'TIMBRE', 'Timbre', TaxFamily::Stamp, null, '1.000', null, false, false, null, 0, 3, $this->now);
        $this->assertRefused('lines[0].taxComponentIds', fn () => $this->create($this->acme, '10', null, null, $stamp, 3), 'a stamp');

        $theirs = $this->percentage(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'TVA19', '19', 3);
        $this->assertRefused('lines[0].taxComponentIds', fn () => $this->create($this->acme, '10', null, null, $theirs, 3), 'another company’s');

        $this->vat19->revise('TVA 19 %', '19', null, null, false, false, false, null, 0, 3, $this->now);
        $this->assertRefused('lines[0].taxComponentIds', fn () => $this->create($this->acme, '10', null, null, $this->vat19, 3), 'an inactive one');
    }

    public function testTheVendorAndTheCategoryAreTheCompanysAndActive(): void
    {
        $theirVendor = Vendor::create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'FRN-0001', new VendorProfile('Autre'), $this->now);
        $this->assertRefused('vendorId', fn () => $this->create($this->acme, '10', $theirVendor, null, null, 3), 'another company’s vendor');

        $retired = Vendor::create($this->acme, 'FRN-0002', new VendorProfile('Ancien'), $this->now);
        $retired->revise('FRN-0002', new VendorProfile('Ancien'), false, $this->now);
        $this->assertRefused('vendorId', fn () => $this->create($this->acme, '10', $retired, null, null, 3), 'an inactive vendor');

        $closed = ExpenseCategory::create($this->acme, 'Ancienne', null, $this->now);
        $closed->revise('Ancienne', null, false, $this->now);
        $this->assertRefused('categoryId', fn () => $this->create($this->acme, '10', null, $closed, null, 3), 'an inactive category');
    }

    /** A payment with no vendor record (a car park, a notary) names who was paid in words; a vendor already names it. */
    public function testAPayeeNamesWhoWasPaidWhenNoVendorDoesAndNeverBesideOne(): void
    {
        $parking = $this->create($this->acme, '5', null, null, null, 3, new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Parking', payee: '  Parking du Lac  '));
        self::assertSame('Parking du Lac', $parking->getPayee());
        self::assertNull($this->create($this->acme, '5', null, null, null, 3, new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Parking', payee: '   '))->getPayee());

        $vendor = Vendor::create($this->acme, 'FRN-0001', new VendorProfile('STEG'), $this->now);
        $this->assertRefused('payee', fn () => $this->create($this->acme, '80', $vendor, null, null, 3, new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Électricité', payee: 'STEG')), 'a payee beside a vendor');
        $this->assertRefused('payee', static fn () => new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Parking', payee: str_repeat('a', ExpenseDetails::PAYEE_MAX + 1)), 'a payee too long');
    }

    public function testADraftIsRevisedThenRecordedOnceItHasACategoryAndNeverRevisedAgain(): void
    {
        $expense = $this->create($this->acme, '10', null, null, null, 3);

        $this->assertRefused('categoryId', fn () => $expense->record($this->now), 'an expense is recorded under a category');

        self::assertSame(['lines', 'categoryId'], $expense->revise($this->details(), ExpenseAmounts::one('20', 3, $this->vat19), null, $this->fuel, 3, RoundingPoint::PerRateGroup, $this->now));
        self::assertSame([], $expense->revise($this->details(), ExpenseAmounts::one('20', 3, $this->vat19), null, $this->fuel, 3, RoundingPoint::PerRateGroup, $this->now));
        self::assertSame(['23.800'], [$expense->getAmountGross()]);

        $expense->record($this->now);
        self::assertSame(ExpenseStatus::Recorded, $expense->getStatus());

        $this->expectException(ExpenseTransitionRefused::class);
        $expense->revise($this->details(), ExpenseAmounts::one('30', 3, null), null, $this->fuel, 3, RoundingPoint::PerRateGroup, $this->now);
    }

    public function testARecordedExpenseIsPaidOnADayFromItsDateToToday(): void
    {
        $expense = $this->create($this->acme, '10', null, $this->fuel, null, 3);
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

    public function testAPaymentWithholdsItsRateOfTheGrossAtTheCurrencysScaleAndHandsOverTheRest(): void
    {
        // docs/SPEC.md § 7, 2026-09-24 11:40 (RPT-09): the withholding on a supplier is recorded on the expense's payment.
        $today = new \DateTimeImmutable('2026-09-15');
        $expense = $this->create($this->acme, '1037.451', null, $this->fuel, $this->vat19, 3);
        $expense->record($this->now);
        self::assertSame(['1234.567', null, null, '1234.567'], [$expense->getAmountGross(), $expense->getWithholdingRate(), $expense->getWithholdingAmount(), $expense->getAmountPaid()], 'nothing is withheld before the payment');

        foreach (['101' => 'over a hundred', '-1' => 'negative', '1.2345' => 'four decimals', 'un' => 'not a number'] as $rate => $case) {
            $this->assertRefused('withholdingRate', fn () => $expense->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), $today, $this->now, (string) $rate, 3), $case);
        }
        self::assertSame(ExpenseStatus::Recorded, $expense->getStatus(), 'a refused rate pays nothing');

        $expense->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), $today, $this->now, ' 1 ', 3);

        self::assertSame(['1.000', '12.346', '1222.221'], [$expense->getWithholdingRate(), $expense->getWithholdingAmount(), $expense->getAmountPaid()], '1 % of 1234.567 is 12.34567, rounded half away from zero to the millime');
    }

    public function testAPaymentAtARateOfZeroWithholdsNothing(): void
    {
        $expense = $this->create($this->acme, '10', null, $this->fuel, null, 2);
        $expense->record($this->now);

        $expense->pay(PaymentMethod::Cash, new \DateTimeImmutable('2026-09-12'), new \DateTimeImmutable('2026-09-15'), $this->now, '0', 2);

        self::assertSame([null, null, '10.000'], [$expense->getWithholdingRate(), $expense->getWithholdingAmount(), $expense->getAmountPaid()]);
    }

    public function testAPaymentRecordsTheTejOperationItWasSaidToBeAndNeverGuessesOne(): void
    {
        $today = new \DateTimeImmutable('2026-09-15');
        $said = $this->create($this->acme, '1000', null, $this->fuel, $this->vat19, 3);
        $said->record($this->now);
        $said->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), $today, $this->now, '1.5', 3, TejOperationCode::Rs7_000001);
        self::assertSame(TejOperationCode::Rs7_000001, $said->getWithholdingOperationCode());

        $unsaid = $this->create($this->acme, '1000', null, $this->fuel, $this->vat19, 3);
        $unsaid->record($this->now);
        $unsaid->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), $today, $this->now, '1.5', 3);
        self::assertNull($unsaid->getWithholdingOperationCode(), 'a rate of 1.5 is RS7_000001 on its face, and still not assumed');

        $exempt = $this->create($this->acme, '1000', null, $this->fuel, $this->vat19, 3);
        $exempt->record($this->now);
        $exempt->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), $today, $this->now, '0', 3, TejOperationCode::Rs7_000006);
        self::assertSame([null, TejOperationCode::Rs7_000006], [$exempt->getWithholdingRate(), $exempt->getWithholdingOperationCode()], 'a supplier exempt from withholding is still declared, at 0 %');

        $french = new Company('Durand', 'FR', 'EUR', 'fr', 'Europe/Paris');
        $abroad = $this->create($french, '1000', null, ExpenseCategory::create($french, 'Loyer', null, $this->now), null, 2);
        $abroad->record($this->now);
        $this->assertRefused('withholdingOperationCode', fn () => $abroad->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), $today, $this->now, null, 2, TejOperationCode::Rs1_000002), 'a TEJ code outside Tunisia');
        self::assertSame(ExpenseStatus::Recorded, $abroad->getStatus(), 'a refused code pays nothing');
    }

    public function testAPaidExpenseIsGivenItsTejOperationAfterwardsAndNothingElseIs(): void
    {
        $expense = $this->create($this->acme, '1000', null, $this->fuel, null, 3);
        foreach (['draft' => null, 'recorded' => 'record'] as $state => $step) {
            if (null !== $step) {
                $expense->record($this->now);
            }
            try {
                $expense->classifyWithholding(TejOperationCode::Rs7_000001, $this->now);
                self::fail("a $state expense was classified");
            } catch (ExpenseTransitionRefused) {
            }
        }
        $expense->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), new \DateTimeImmutable('2026-09-15'), $this->now, '1', 3);

        self::assertTrue($expense->classifyWithholding(TejOperationCode::Rs7_000002, $this->now));
        self::assertSame(TejOperationCode::Rs7_000002, $expense->getWithholdingOperationCode());
        self::assertFalse($expense->classifyWithholding(TejOperationCode::Rs7_000002, $this->now), 'the same code again changes nothing');
        self::assertTrue($expense->classifyWithholding(null, $this->now));
        self::assertNull($expense->getWithholdingOperationCode());
    }

    public function testTheOperationCodesAreTheAdministrationsFortySevenWithTheirPublishedLabels(): void
    {
        self::assertCount(47, TejOperationCode::cases());
        self::assertSame('RS7_000001', TejOperationCode::Rs7_000001->value);
        self::assertSame('Loyers servis à des résidents établis', TejOperationCode::Rs1_000002->label());
        foreach (TejOperationCode::cases() as $code) {
            self::assertMatchesRegularExpression('/^RS(1[01]|[1-9])_0000\d\d$/', $code->value);
            self::assertNotSame('', $code->label(), $code->value);
        }
    }

    public function testTheDueDayIsTheVendorsPaymentTermsAfterTheExpensesDate(): void
    {
        $sotumag = Vendor::create($this->acme, 'FRN-0001', new VendorProfile('Sotumag', paymentTermsDays: 30), $this->now);
        $cash = Vendor::create($this->acme, 'FRN-0002', new VendorProfile('Kiosque'), $this->now);

        self::assertSame('2026-10-10', $this->create($this->acme, '10', $sotumag, null, null, 3)->getDueDate()?->format('Y-m-d'));
        self::assertNull($this->create($this->acme, '10', $cash, null, null, 3)->getDueDate(), 'a vendor without terms');
        self::assertNull($this->create($this->acme, '10', null, null, null, 3)->getDueDate(), 'no vendor');
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

    private function details(): ExpenseDetails
    {
        return new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Gasoil septembre', 'F-2026-118');
    }

    /** A receipt: one line at one rate or none. */
    private function create(Company $company, string $net, ?Vendor $vendor, ?ExpenseCategory $category, ?TaxComponent $tax, int $scale, ?ExpenseDetails $details = null): Expense
    {
        return Expense::create($company, $details ?? $this->details(), ExpenseAmounts::one($net, $scale, $tax), $vendor, $category, $scale, RoundingPoint::PerRateGroup, $this->now);
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
