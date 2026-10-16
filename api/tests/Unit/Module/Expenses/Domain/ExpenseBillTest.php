<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Unit\Module\Expenses\Domain;

use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\ValueObject\TaxFamily;
use App\Module\Expenses\Domain\Exception\InvalidExpense;
use App\Module\Expenses\Domain\Model\Expense;
use App\Module\Expenses\Domain\Model\ExpenseCategory;
use App\Module\Expenses\Domain\Model\ExpenseTax;
use App\Module\Expenses\Domain\ValueObject\ExpenseAmountLine;
use App\Module\Expenses\Domain\ValueObject\ExpenseAmounts;
use App\Module\Expenses\Domain\ValueObject\ExpenseDetails;
use App\Module\Expenses\Domain\ValueObject\WithholdingOperation;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Tenancy\Domain\Model\Company;
use PHPUnit\Framework\TestCase;

/**
 * A supplier's bill entered as it is printed (docs/plans/expense-taxes.plan.md): several VAT rates, FODEC under the VAT,
 * the stamp, worked out as an invoice is, a printed amount taken within a millime a line, and the withholding taken on
 * each VAT rate's own amount taxes included, as TEJ declares it (docs/research/tax-data-tunisia.md § 2.2).
 */
final class ExpenseBillTest extends TestCase
{
    private \DateTimeImmutable $now;
    private Company $acme;
    private TaxComponent $vat19;
    private TaxComponent $vat7;
    private TaxComponent $fodec;
    private TaxComponent $stamp;
    private ExpenseCategory $hardware;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-09-15 09:00:00');
        $this->acme = new Company('Acme', 'TN', 'TND', 'fr', 'Africa/Tunis');
        $this->vat19 = TaxComponent::create($this->acme, 'TVA19', 'TVA 19 %', TaxFamily::Vat, '19', null, null, false, false, null, 0, 3, $this->now);
        $this->vat7 = TaxComponent::create($this->acme, 'TVA7', 'TVA 7 %', TaxFamily::Vat, '7', null, null, false, false, null, 1, 3, $this->now);
        $this->fodec = TaxComponent::create($this->acme, 'FODEC', 'FODEC', TaxFamily::Levy, '1', null, null, true, false, null, 2, 3, $this->now);
        $this->hardware = ExpenseCategory::create($this->acme, 'Quincaillerie', null, $this->now);
        $this->stamp = TaxComponent::create($this->acme, 'TIMBRE', 'Timbre', TaxFamily::Stamp, null, '1.000', null, false, false, null, 3, 3, $this->now);
    }

    public function testABillAtTwoRatesWithFodecAndTheStampIsWorkedOutAsAnInvoiceIs(): void
    {
        $expense = $this->bill();

        self::assertSame(['1500.000', '236.900', '1.000', '1737.900', '1736.900'], [$expense->getAmountNet(), $expense->getTaxAmount(), $expense->getFixedAmount(), $expense->getAmountGross(), $expense->getWithholdingBase()]);
        self::assertSame([
            'TVA19' => ['percentage_line', '19.000', '1010.000', '191.900', null],
            'FODEC' => ['percentage_line', '1.000', '1000.000', '10.000', null],
            'TVA7' => ['percentage_line', '7.000', '500.000', '35.000', null],
            'TIMBRE' => ['fixed_document', null, null, '1.000', null],
        ], $this->taxes($expense), 'FODEC enters the 19 % base; the stamp is in no base');
        self::assertSame([['1000.000', ['TVA19', 'FODEC']], ['500.000', ['TVA7']]], array_map(static fn ($line): array => [$line->getAmountNet(), $line->getTaxCodes()], $expense->getLines()));
    }

    public function testAPrintedAmountReplacesTheWorkedOutOneWithinAMillimeALineAndNoFurther(): void
    {
        $lines = [new ExpenseAmountLine('33.335', [$this->vat19], 3), new ExpenseAmountLine('33.335', [$this->vat19], 3), new ExpenseAmountLine('33.335', [$this->vat19], 3)];

        $worked = $this->expense(new ExpenseAmounts($lines));
        self::assertSame(['19.001', '119.006'], [$worked->getTaxAmount(), $worked->getAmountGross()], '19 % of 100.005 rounded once');

        $printed = $this->expense(new ExpenseAmounts($lines, printed: ['TVA19' => '19.004']));
        self::assertSame(['19.004', '119.009'], [$printed->getTaxAmount(), $printed->getAmountGross()], 'three lines may each round a millime apart');
        self::assertSame(['percentage_line', '19.000', '100.005', '19.004', '19.001'], $this->taxes($printed)['TVA19'], 'what was worked out is kept beside what was printed');

        $this->assertRefused('printedTaxes', fn () => $this->expense(new ExpenseAmounts($lines, printed: ['TVA19' => '19.005'])), 'four millimes on three lines');
        $this->assertRefused('printedTaxes', fn () => $this->expense(new ExpenseAmounts($lines, printed: ['TVA7' => '1.000'])), 'a tax the bill does not carry');
        $this->assertRefused('printedTaxes', fn () => $this->expense(new ExpenseAmounts($lines, [$this->stamp], ['TIMBRE' => '0.600'])), 'a fixed charge is its component\'s');
        $this->assertRefused('printedTaxes', fn () => $this->expense(new ExpenseAmounts($lines, printed: ['TVA19' => '19.0015'])), 'finer than the currency');
    }

    public function testThePaymentWithholdsOnEachVatRatesAmountTaxesIncludedAndTheStampOnNone(): void
    {
        $expense = $this->bill();
        $expense->record($this->now);

        self::assertEquals([
            new WithholdingOperation('19.000', '1010.000', '191.900', '1201.900'),
            new WithholdingOperation('7.000', '500.000', '35.000', '535.000'),
        ], $expense->getWithholdingOperations(), 'one operation a VAT rate, FODEC inside its amount excluding VAT');

        $expense->pay(PaymentMethod::Transfer, new \DateTimeImmutable('2026-09-12'), new \DateTimeImmutable('2026-09-15'), $this->now, '1', 3);

        self::assertSame(['1.000', '17.369', '1720.531'], [$expense->getWithholdingRate(), $expense->getWithholdingAmount(), $expense->getAmountPaid()], '12.019 on 1201.900 and 5.350 on 535.000; the stamp is handed over whole');
        self::assertSame(['12.019', '5.350'], array_map(static fn (WithholdingOperation $operation): string => $operation->withheld('1.000', 3), $expense->getWithholdingOperations()));
    }

    public function testWhatNoVatTaxesIsAnOperationAtNoRate(): void
    {
        $expense = $this->expense(new ExpenseAmounts([new ExpenseAmountLine('1000', [$this->vat19], 3), new ExpenseAmountLine('200', [], 3)]));

        self::assertEquals([
            new WithholdingOperation('19.000', '1000.000', '190.000', '1190.000'),
            new WithholdingOperation(null, '200.000', '0.000', '200.000'),
        ], $expense->getWithholdingOperations());
    }

    public function testABillNamesAtLeastOneLineOfItsCompanysActiveRatesAndOnlyFixedChargesBesideThem(): void
    {
        $withholding = TaxComponent::create($this->acme, 'RS1', 'Retenue', TaxFamily::Withholding, '1', null, '1000', false, false, null, 4, 3, $this->now);
        $theirs = TaxComponent::create(new Company('Globex', 'TN', 'TND', 'fr', 'Africa/Tunis'), 'TVA19', 'TVA 19 %', TaxFamily::Vat, '19', null, null, false, false, null, 0, 3, $this->now);

        $this->assertRefused('lines', static fn () => new ExpenseAmounts([]), 'no line');
        $this->assertRefused('amountNet', static fn () => new ExpenseAmountLine('0', [], 3), 'a net of zero, named by the line alone');
        $this->assertRefused('lines[0].amountNet', fn () => $this->expense(new ExpenseAmounts([new ExpenseAmountLine('10.005', [], 3)]), 2), 'finer than a euro');
        foreach (['a stamp' => $this->stamp, 'a withholding' => $withholding, 'another company\'s' => $theirs] as $case => $tax) {
            $this->assertRefused('lines[1].taxComponentIds', fn () => $this->expense(new ExpenseAmounts([new ExpenseAmountLine('10', [], 3), new ExpenseAmountLine('10', [$tax], 3)])), $case);
        }
        $this->assertRefused('lines[0].taxComponentIds', fn () => $this->expense(new ExpenseAmounts([new ExpenseAmountLine('10', [$this->vat19, $this->vat19], 3)])), 'the same rate twice');
        $this->assertRefused('lines[0].taxComponentIds', fn () => $this->expense(new ExpenseAmounts([new ExpenseAmountLine('10', [$this->vat19, $this->vat7], 3)])), 'two VAT rates on one line, which TEJ declares as one operation each');
        $this->assertRefused('documentTaxComponentIds', fn () => $this->expense(new ExpenseAmounts([new ExpenseAmountLine('10', [], 3)], [$this->vat19])), 'a rate as a fixed charge');
        $this->assertRefused('documentTaxComponentIds', fn () => $this->expense(new ExpenseAmounts([new ExpenseAmountLine('10', [], 3)], [$this->stamp, $this->stamp])), 'the stamp twice');

        $this->vat7->revise('TVA 7 %', '7', null, null, false, false, false, null, 1, 3, $this->now);
        $this->assertRefused('lines[0].taxComponentIds', fn () => $this->expense(new ExpenseAmounts([new ExpenseAmountLine('10', [$this->vat7], 3)])), 'an inactive rate');
    }

    public function testARevisionNamesTheBillsFieldThatChangedAndNothingWhenItSaysTheSame(): void
    {
        $expense = $this->bill();

        self::assertSame([], $expense->revise($this->details(), $this->amounts(), null, $this->hardware, 3, RoundingPoint::PerRateGroup, $this->now));
        self::assertSame(['lines'], $expense->revise($this->details(), new ExpenseAmounts([new ExpenseAmountLine('1000', [$this->vat19, $this->fodec], 3)], [$this->stamp]), null, $this->hardware, 3, RoundingPoint::PerRateGroup, $this->now));
        self::assertSame('1202.900', $expense->getAmountGross());
        self::assertSame(['printedTaxes'], $expense->revise($this->details(), new ExpenseAmounts([new ExpenseAmountLine('1000', [$this->vat19, $this->fodec], 3)], [$this->stamp], ['TVA19' => '191.901']), null, $this->hardware, 3, RoundingPoint::PerRateGroup, $this->now), 'a printed amount is a change');
        self::assertSame(['documentTaxComponentIds', 'printedTaxes'], $expense->revise($this->details(), new ExpenseAmounts([new ExpenseAmountLine('1000', [$this->vat19, $this->fodec], 3)]), null, $this->hardware, 3, RoundingPoint::PerRateGroup, $this->now), 'the stamp left out, and the printed amount with it');
    }

    private function bill(): Expense
    {
        return $this->expense($this->amounts());
    }

    private function amounts(): ExpenseAmounts
    {
        return new ExpenseAmounts([new ExpenseAmountLine('1000', [$this->vat19, $this->fodec], 3), new ExpenseAmountLine('500', [$this->vat7], 3)], [$this->stamp]);
    }

    private function expense(ExpenseAmounts $amounts, int $scale = 3): Expense
    {
        return Expense::create($this->acme, $this->details(), $amounts, null, $this->hardware, $scale, RoundingPoint::PerRateGroup, $this->now);
    }

    private function details(): ExpenseDetails
    {
        return new ExpenseDetails(new \DateTimeImmutable('2026-09-10'), 'Quincaillerie septembre', 'F-2026-118');
    }

    /** @return array<string, list<string|null>> */
    private function taxes(Expense $expense): array
    {
        $taxes = [];
        foreach ($expense->getTaxes() as $tax) {
            self::assertInstanceOf(ExpenseTax::class, $tax);
            $taxes[$tax->getCode()] = [$tax->getKind()->value, $tax->getRate(), $tax->getBase(), $tax->getAmount(), $tax->getWorkedOutAmount() === $tax->getAmount() ? null : $tax->getWorkedOutAmount()];
        }

        return $taxes;
    }

    /** A line keeps no more decimals than its currency has, three at most, and says how many it takes. */
    public function testALineTakesTheDecimalsOfItsCurrency(): void
    {
        self::assertSame('1200.000', new ExpenseAmountLine('1200', [], 0)->amountNet, 'a currency without decimals');
        self::assertSame('10.050', new ExpenseAmountLine('10.05', [], 2)->amountNet);
        foreach ([['10.5', 0, 0], ['10.005', 2, 2], ['10.0005', 4, 3]] as [$net, $scale, $told]) {
            try {
                new ExpenseAmountLine($net, [], $scale);
                self::fail("$net at $scale decimals was accepted");
            } catch (InvalidExpense $refused) {
                self::assertSame(['amountNet', 'invalid_amount', ['decimals' => $told]], [$refused->field, $refused->reason, $refused->params], "$net at $scale");
            }
        }
    }

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
