<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain\Model;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\RoundingPoint;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Fiscal\Domain\Model\TaxComponent;
use App\Fiscal\Domain\ValueObject\TaxKind;
use App\Module\Expenses\Domain\Exception\ExpenseTransitionRefused;
use App\Module\Expenses\Domain\Exception\InvalidExpense;
use App\Module\Expenses\Domain\ValueObject\ExpenseAmountLine;
use App\Module\Expenses\Domain\ValueObject\ExpenseAmounts;
use App\Module\Expenses\Domain\ValueObject\ExpenseDetails;
use App\Module\Expenses\Domain\ValueObject\ExpenseStatus;
use App\Module\Expenses\Domain\ValueObject\TejOperationCode;
use App\Module\Expenses\Domain\ValueObject\WithholdingOperation;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Shared\Domain\Service\CompanyOwned;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Tenancy\Domain\Model\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Money a company spent (docs/SPEC.md § 4 expense), entered as the supplier's bill prints it (docs/plans/expense-taxes
 * .plan.md): lines each a net and the rates on it, the fixed charges it carries such as the stamp, worked out by the
 * same calculator as an invoice, a tax printed a millime or so apart taken as printed. The totals are stored as they
 * were worked out. From a vendor, under a category. A draft is revised or deleted; a recorded expense is what the books
 * rest on and only gets paid, on a day from its own to today.
 */
#[ORM\Entity]
#[ORM\Table(name: 'expense')]
#[ORM\Index(name: 'idx_expense_company_date', columns: ['company_id', 'expense_date'])]
#[ORM\Index(name: 'idx_expense_vendor', columns: ['vendor_id'])]
#[ORM\Index(name: 'idx_expense_category', columns: ['category_id'])]
#[ORM\Index(name: 'idx_expense_amount_to_check', columns: ['company_id', 'expense_date'], options: ['where' => 'amount_to_check'])]
class Expense implements CompanyOwned
{
    private const string WITHHOLDING_RATE = '/^(0|[1-9][0-9]{0,2})(\.[0-9]{1,3})?$/';
    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: 16, enumType: ExpenseStatus::class)]
    private ExpenseStatus $status = ExpenseStatus::Draft;

    #[ORM\Column(name: 'expense_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(length: ExpenseDetails::REFERENCE_MAX, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: ExpenseDetails::DESCRIPTION_MAX)]
    private string $description;

    #[ORM\ManyToOne(targetEntity: Vendor::class)]
    #[ORM\JoinColumn(name: 'vendor_id', nullable: true)]
    private ?Vendor $vendor = null;

    /** Who was paid, in words, when no vendor record names them. */
    #[ORM\Column(length: ExpenseDetails::PAYEE_MAX, nullable: true)]
    private ?string $payee = null;

    #[ORM\ManyToOne(targetEntity: ExpenseCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true)]
    private ?ExpenseCategory $category = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amountNet;

    /** @var Collection<int, ExpenseLine> */
    #[ORM\OneToMany(targetEntity: ExpenseLine::class, mappedBy: 'expense', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $lines;

    /** @var Collection<int, ExpenseTax> */
    #[ORM\OneToMany(targetEntity: ExpenseTax::class, mappedBy: 'expense', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $taxes;

    /** What the rates come to, as the bill prints them. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $taxAmount;

    /** What the fixed charges come to: the stamp. */
    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $fixedAmount = '0.000';

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amountGross;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 16, nullable: true, enumType: PaymentMethod::class)]
    private ?PaymentMethod $paymentMethod = null;

    #[ORM\Column(name: 'payment_date', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $paidOn = null;

    /**
     * What the company withheld from the supplier when paying, as a percentage and an amount at the currency's scale
     * (docs/SPEC.md § 7, 2026-09-24 11:40, RPT-09): the supplier is handed the gross less it. Null when nothing was.
     */
    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $withholdingRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3, nullable: true)]
    private ?string $withholdingAmount = null;

    /**
     * What the payment is to Tunisia's TEJ platform, which declares the withholding under it (docs/research/
     * tax-data-tunisia.md § 2.2); said by whoever pays, never worked out from the rate. Null when nobody said.
     */
    #[ORM\Column(length: 16, nullable: true, enumType: TejOperationCode::class)]
    private ?TejOperationCode $withholdingOperationCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    /** A draft whose amount a person has yet to check against the bill, as a recurring expense of variable amount drafts it. */
    #[ORM\Column(options: ['default' => false])]
    private bool $amountToCheck = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** Doctrine counts each save, so an editor of an older version is told rather than overwriting it. */
    #[ORM\Version]
    #[ORM\Column(type: Types::INTEGER, options: ['default' => 1])]
    private int $version = 1;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->currency = $company->getCurrency();
        $this->lines = new ArrayCollection();
        $this->taxes = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param int           $currencyScale the company currency's decimals
     * @param RoundingPoint $rounding      where its preset rounds a rate
     *
     * @throws InvalidExpense
     */
    public static function create(Company $company, ExpenseDetails $details, ExpenseAmounts $amounts, ?Vendor $vendor, ?ExpenseCategory $category, int $currencyScale, RoundingPoint $rounding, \DateTimeImmutable $now): self
    {
        $expense = new self($company, $now);
        $expense->apply($details, $amounts, $vendor, $category, $currencyScale, $rounding);

        return $expense;
    }

    /**
     * @return list<string> the fields that changed, none when the revision says what the expense already says
     *
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function revise(ExpenseDetails $details, ExpenseAmounts $amounts, ?Vendor $vendor, ?ExpenseCategory $category, int $currencyScale, RoundingPoint $rounding, \DateTimeImmutable $now): array
    {
        $this->assertDraft('revised');
        $before = $this->state();
        $this->apply($details, $amounts, $vendor, $category, $currencyScale, $rounding);
        $after = $this->state();

        $changed = array_keys(array_filter($after, static fn (mixed $value, string $field) => $value !== $before[$field], \ARRAY_FILTER_USE_BOTH));
        if ([] !== $changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /**
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function record(\DateTimeImmutable $now): void
    {
        $this->assertDraft('recorded');
        if (null === $this->category) {
            throw new InvalidExpense('categoryId', 'An expense is recorded under a category.', 'value_required');
        }
        $this->status = ExpenseStatus::Recorded;
        $this->amountToCheck = false;
        $this->updatedAt = $now;
    }

    /** Its amount is to be checked against the bill before it is recorded: recording it is the check. */
    public function askAmountChecked(): void
    {
        $this->assertDraft('flagged');
        $this->amountToCheck = true;
    }

    public function isAmountToCheck(): bool
    {
        return $this->amountToCheck;
    }

    /**
     * @param \DateTimeImmutable    $today           the company's own day
     * @param string|null           $withholdingRate the percentage withheld from the supplier, 0 to 100 with at most three
     *                                               decimals; null or 0 for none
     * @param TejOperationCode|null $operationCode   what the payment is to the TEJ platform, kept whatever the rate:
     *                                               a supplier exempt from withholding is declared at 0 %
     *
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function pay(PaymentMethod $method, \DateTimeImmutable $paidOn, \DateTimeImmutable $today, \DateTimeImmutable $now, ?string $withholdingRate = null, int $currencyScale = 3, ?TejOperationCode $operationCode = null): void
    {
        if (ExpenseStatus::Recorded !== $this->status) {
            throw new ExpenseTransitionRefused(\sprintf('The expense is %s: only a recorded expense is paid.', $this->status->value));
        }
        $day = self::day($paidOn);
        if ($day < $this->date) {
            throw new InvalidExpense('paidOn', \sprintf('An expense is paid on or after its own day, %s.', $this->date->format('Y-m-d')), 'paid_before_expense');
        }
        if ($day > self::day($today)) {
            throw new InvalidExpense('paidOn', 'A payment is recorded once it happened, today at the latest.', 'paid_after_today');
        }
        $this->assertDeclarableToTej($operationCode);
        $rate = TypedText::trimmed($withholdingRate ?? '');
        if ('' !== $rate && (1 !== preg_match(self::WITHHOLDING_RATE, $rate) || Decimal::of($rate)->compare(100) > 0)) {
            throw new InvalidExpense('withholdingRate', 'A withholding is a percentage from 0 to 100 with at most three decimals.', 'invalid_withholding_rate');
        }
        if ('' === $rate || 0 === Decimal::of($rate)->compare(0)) {
            $this->withholdingRate = null;
            $this->withholdingAmount = null;
        } else {
            // Taken on each VAT rate's part on its own, as TEJ declares it; the stamp is in none of them.
            $this->withholdingRate = Decimal::format(Decimal::of($rate), 3);
            $withheld = Decimal::sum(array_map(fn (WithholdingOperation $operation) => Decimal::of($operation->withheld((string) $this->withholdingRate, $currencyScale)), $this->getWithholdingOperations()));
            $this->withholdingAmount = self::stored($withheld);
        }

        $this->withholdingOperationCode = $operationCode;
        $this->paymentMethod = $method;
        $this->paidOn = $day;
        $this->status = ExpenseStatus::Paid;
        $this->updatedAt = $now;
    }

    /**
     * Says, or corrects, what a payment already made is to the TEJ platform: a payment recorded before anyone said
     * would otherwise never be declared. Null takes it back.
     *
     * @return bool whether anything changed
     *
     * @throws ExpenseTransitionRefused when the expense is not paid
     * @throws InvalidExpense           when its company does not declare to TEJ
     */
    public function classifyWithholding(?TejOperationCode $operationCode, \DateTimeImmutable $now): bool
    {
        if (ExpenseStatus::Paid !== $this->status) {
            throw new ExpenseTransitionRefused(\sprintf('The expense is %s: only a paid expense has a withholding to classify.', $this->status->value));
        }
        $this->assertDeclarableToTej($operationCode);
        if ($operationCode === $this->withholdingOperationCode) {
            return false;
        }
        $this->withholdingOperationCode = $operationCode;
        $this->updatedAt = $now;

        return true;
    }

    /** @throws InvalidExpense when a TEJ code is said for a company outside the Tunisian preset */
    private function assertDeclarableToTej(?TejOperationCode $operationCode): void
    {
        if (null !== $operationCode && TejOperationCode::PRESET !== $this->company->getFiscalPreset()) {
            throw new InvalidExpense('withholdingOperationCode', 'A TEJ operation code is said for a company under the Tunisian preset only.', 'tej_outside_tunisia');
        }
    }

    /** @throws ExpenseTransitionRefused when the expense is no longer a draft */
    public function assertDraft(string $action): void
    {
        if (ExpenseStatus::Draft !== $this->status) {
            throw new ExpenseTransitionRefused(\sprintf('The expense is %s: only a draft is %s.', $this->status->value, $action));
        }
    }

    public function getPayee(): ?string
    {
        return $this->payee;
    }

    /** The vendor's payment terms counted from the expense's day; none without a vendor or its terms. */
    public function getDueDate(): ?\DateTimeImmutable
    {
        $days = $this->vendor?->getProfile()->paymentTermsDays;

        return null === $days ? null : $this->date->modify(\sprintf('+%d days', $days));
    }

    private function apply(ExpenseDetails $details, ExpenseAmounts $amounts, ?Vendor $vendor, ?ExpenseCategory $category, int $currencyScale, RoundingPoint $rounding): void
    {
        if (null !== $vendor && (!$vendor->getCompany()->getId()->equals($this->company->getId()) || (!$vendor->isActive() && $vendor !== $this->vendor))) {
            throw new InvalidExpense('vendorId', 'An expense names an active vendor of its company.', 'unknown_choice');
        }
        if (null !== $vendor && null !== $details->payee) {
            throw new InvalidExpense('payee', 'A vendor already names who was paid.');
        }
        if (null !== $category && (!$category->getCompany()->getId()->equals($this->company->getId()) || (!$category->isActive() && $category !== $this->category))) {
            throw new InvalidExpense('categoryId', 'An expense is filed under an active category of its company.', 'unknown_choice');
        }
        $figures = $this->figures($amounts, $currencyScale, $rounding);

        // Doctrine compares a date by its instance: a new one of the same day would be written, and count as a save.
        if (!isset($this->date) || $this->date->format('Y-m-d') !== $details->date->format('Y-m-d')) {
            $this->date = $details->date;
        }
        $this->reference = $details->reference;
        $this->description = $details->description;
        $this->vendor = $vendor;
        $this->payee = $details->payee;
        $this->category = $category;
        $this->notes = $details->notes;
        if ($figures['written'] !== $this->written()) {
            $this->lines->clear();
            foreach ($amounts->lines as $at => $line) {
                $this->lines->add(new ExpenseLine($this, $at, $line->amountNet, $line->taxes));
            }
            $this->taxes->clear();
            foreach ($figures['taxes'] as $at => [$component, $rate, $base, $worked, $amount]) {
                $this->taxes->add(new ExpenseTax($this, $at, $component, $rate, $base, $worked, $amount));
            }
        }
        $this->amountNet = $figures['net'];
        $this->taxAmount = $figures['tax'];
        $this->fixedAmount = $figures['fixed'];
        $this->amountGross = self::stored(Decimal::of($figures['net'])->add(Decimal::of($figures['tax']))->add(Decimal::of($figures['fixed'])));
    }

    /**
     * What a bill comes to, through the calculator invoices use: each tax with its rate, base, worked-out and charged
     * amounts, and the totals. `written` is what the expense would store, to leave its rows alone when nothing moved.
     *
     * @return array{net: string, tax: string, fixed: string, taxes: list<array{TaxComponent, ?string, ?string, string, string}>, written: string}
     *
     * @throws InvalidExpense
     */
    private function figures(ExpenseAmounts $amounts, int $currencyScale, RoundingPoint $rounding): array
    {
        $kept = $this->components();
        $lines = [];
        $components = [];
        $carriers = [];
        foreach ($amounts->lines as $at => $line) {
            $net = Decimal::of($line->amountNet);
            if (0 !== Decimal::round($net, $currencyScale)->compare($net)) {
                throw (new InvalidExpense('amountNet', \sprintf('A net amount carries at most %d decimals, the currency\'s.', $currencyScale), 'invalid_amount', ['decimals' => $currencyScale]))->within("lines[$at]");
            }
            $taxes = [];
            $outsideVatBase = 0;
            foreach ($line->taxes as $tax) {
                if (!$this->ownedAndActive($tax, $kept)) {
                    throw (new InvalidExpense('taxComponentIds', 'A line is taxed by active rates of its company.', 'unknown_tax'))->within("lines[$at]");
                }
                if (TaxKind::FixedDocument === $tax->getKind()) {
                    throw (new InvalidExpense('taxComponentIds', 'A fixed charge is carried by the bill, not by a line.', 'tax_on_document', ['tax' => $tax->getName()]))->within("lines[$at]");
                }
                if (TaxKind::PercentageLine !== $tax->getKind() || isset($taxes[$tax->getCode()])) {
                    throw (new InvalidExpense('taxComponentIds', 'A line is taxed by rates on the net, each once.'))->within("lines[$at]");
                }
                if (!$tax->entersVatBase() && ++$outsideVatBase > 1) {
                    throw (new InvalidExpense('taxComponentIds', 'A line carries one VAT rate: a bill at two rates has a line for each.', 'one_vat_rate'))->within("lines[$at]");
                }
                $taxes[$tax->getCode()] = TaxInput::percentage($tax->getCode(), Rate::fromPercentage($tax->getRate() ?? '0'), $tax->entersVatBase());
                $components[$tax->getCode()] = $tax;
                $carriers[$tax->getCode()] = ($carriers[$tax->getCode()] ?? 0) + 1;
            }
            $lines[] = new LineInput('1', $line->amountNet, null, array_values($taxes));
        }
        $charges = [];
        foreach ($amounts->charges as $charge) {
            if (!$this->ownedAndActive($charge, $kept) || TaxKind::FixedDocument !== $charge->getKind() || isset($charges[$charge->getCode()])) {
                throw new InvalidExpense('documentTaxComponentIds', 'A bill carries active fixed charges of its company, each once.', $this->ownedAndActive($charge, $kept) ? null : 'unknown_tax');
            }
            $charges[$charge->getCode()] = TaxInput::fixed($charge->getCode(), $charge->getAmount() ?? '0');
            $components[$charge->getCode()] = $charge;
        }

        try {
            $totals = new DocumentCalculator()->calculate(new DocumentInput($currencyScale, false, TaxBasis::Exclusive, $rounding, $lines, null, array_values($charges)));
        } catch (InvalidDocument|UnsupportedTaxCombination $refused) {
            throw new InvalidExpense($refused->field('amountNet'), $refused->getMessage());
        }

        $unit = Decimal::of('1')->div(10 ** $currencyScale, $currencyScale);
        foreach ($amounts->printed as $code => $amount) {
            $worked = array_find($totals->taxes, static fn ($tax): bool => $tax->code === $code);
            $printed = TypedText::trimmed($amount);
            // One unit of the last decimal off per line carrying the rate, each line having rounded on its own.
            $tolerance = $unit->mul($carriers[$code] ?? 1);
            if (null === $worked || 1 !== preg_match('/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$/', $printed) || 0 !== Decimal::round(Decimal::of($printed), $currencyScale)->compare(Decimal::of($printed))
                || Decimal::absolute(Decimal::of($printed)->sub(Decimal::of($worked->amount)))->compare($tolerance) > 0) {
                throw new InvalidExpense('printedTaxes', \sprintf('A printed amount is a rate of this bill, at most %s from what it works out to.', Decimal::format($tolerance, $currencyScale)), 'printed_tax_off', ['code' => (string) $code, 'tolerance' => Decimal::format($tolerance, $currencyScale)]);
            }
        }

        $rows = [];
        $tax = Decimal::zero();
        foreach ($totals->taxes as $total) {
            $worked = self::stored(Decimal::of($total->amount));
            $charged = isset($amounts->printed[$total->code]) ? self::stored(Decimal::of(TypedText::trimmed($amounts->printed[$total->code]))) : $worked;
            $rows[] = [$components[$total->code], Decimal::format(Decimal::of($total->rate->percentage()), 3), self::stored(Decimal::of($total->base)), $worked, $charged];
            $tax = $tax->add(Decimal::of($charged));
        }
        $fixed = Decimal::zero();
        foreach ($totals->fixedCharges as $charge) {
            $amount = self::stored(Decimal::of($charge->amount));
            $rows[] = [$components[$charge->code], null, null, $amount, $amount];
            $fixed = $fixed->add(Decimal::of($charge->amount));
        }

        return [
            'net' => self::stored(Decimal::of($totals->subtotalNet)),
            'tax' => self::stored($tax),
            'fixed' => self::stored($fixed),
            'taxes' => $rows,
            'written' => self::writtenOf(
                array_map(static fn (ExpenseAmountLine $line): array => [$line->amountNet, array_map(static fn (TaxComponent $tax): string => $tax->getId()->toRfc4122().'@'.Decimal::format(Decimal::of($tax->getRate() ?? '0'), 3), $line->taxes)], $amounts->lines),
                array_map(static fn (array $row): array => [$row[0]->getId()->toRfc4122(), $row[1], $row[2], $row[3], $row[4]], $rows),
            ),
        ];
    }

    /** What the expense stores of its bill, written the same way as what a revision would store. */
    private function written(): string
    {
        return self::writtenOf(
            array_map(static fn (ExpenseLine $line): array => [$line->getAmountNet(), array_map(static fn (ExpenseLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122().'@'.$tax->getRate(), $line->getTaxes())], $this->getLines()),
            array_map(static fn (ExpenseTax $tax): array => [$tax->getTaxComponent()->getId()->toRfc4122(), $tax->getRate(), $tax->getBase(), $tax->getWorkedOutAmount(), $tax->getAmount()], $this->getTaxes()),
        );
    }

    /**
     * @param list<array{string, list<string>}>                     $lines
     * @param list<array{string, ?string, ?string, string, string}> $taxes
     */
    private static function writtenOf(array $lines, array $taxes): string
    {
        return json_encode([$lines, $taxes], \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, true> the components this expense already names, by id, which it keeps once switched off */
    private function components(): array
    {
        $ids = [];
        foreach ($this->getTaxes() as $tax) {
            $ids[$tax->getTaxComponent()->getId()->toRfc4122()] = true;
        }
        foreach ($this->getLines() as $line) {
            foreach ($line->getTaxes() as $tax) {
                $ids[$tax->getTaxComponent()->getId()->toRfc4122()] = true;
            }
        }

        return $ids;
    }

    /** @param array<string, true> $kept */
    private function ownedAndActive(TaxComponent $tax, array $kept): bool
    {
        return $tax->getCompany()->getId()->equals($this->company->getId()) && ($tax->isActive() || isset($kept[$tax->getId()->toRfc4122()]));
    }

    /** @return array<string, mixed> every revisable value by the field a revision names */
    private function state(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'reference' => $this->reference,
            'description' => $this->description,
            'lines' => json_encode(array_map(static fn (ExpenseLine $line): array => [$line->getAmountNet(), array_map(static fn (ExpenseLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $line->getTaxes())], $this->getLines()), \JSON_THROW_ON_ERROR),
            'documentTaxComponentIds' => json_encode(array_values(array_map(static fn (ExpenseTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), array_filter($this->getTaxes(), static fn (ExpenseTax $tax): bool => null === $tax->getRate()))), \JSON_THROW_ON_ERROR),
            'printedTaxes' => json_encode(array_values(array_map(static fn (ExpenseTax $tax): array => [$tax->getCode(), $tax->getAmount()], array_filter($this->getTaxes(), static fn (ExpenseTax $tax): bool => null !== $tax->getRate() && $tax->isPrinted()))), \JSON_THROW_ON_ERROR),
            'vendorId' => $this->vendor?->getId()->toRfc4122(),
            'payee' => $this->payee,
            'categoryId' => $this->category?->getId()->toRfc4122(),
            'notes' => $this->notes,
        ];
    }

    /** Written with its column's three decimals, as the database gives it back. */
    private static function stored(\BcMath\Number $value): string
    {
        return (string) $value->add(0, 3);
    }

    private static function day(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment->format('Y-m-d'), new \DateTimeZone('UTC'));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getStatus(): ExpenseStatus
    {
        return $this->status;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getVendor(): ?Vendor
    {
        return $this->vendor;
    }

    public function getCategory(): ?ExpenseCategory
    {
        return $this->category;
    }

    /** Three decimals. */
    public function getAmountNet(): string
    {
        return $this->amountNet;
    }

    /** @return list<ExpenseLine> in the order the bill prints them */
    public function getLines(): array
    {
        return array_values($this->lines->toArray());
    }

    /** @return list<ExpenseTax> the rates in the order the bill prints them, then the fixed charges */
    public function getTaxes(): array
    {
        return array_values($this->taxes->toArray());
    }

    /** Three decimals. */
    public function getTaxAmount(): string
    {
        return $this->taxAmount;
    }

    /** What the fixed charges come to, three decimals. */
    public function getFixedAmount(): string
    {
        return $this->fixedAmount;
    }

    /** Three decimals. */
    public function getAmountGross(): string
    {
        return $this->amountGross;
    }

    /** What a withholding's threshold is read against: the gross less the fixed charges (docs/fiscal/TN.md § 5a). */
    public function getWithholdingBase(): string
    {
        return self::stored(Decimal::of($this->amountGross)->sub(Decimal::of($this->fixedAmount)));
    }

    /**
     * The bill by VAT rate, as TEJ declares a payment (docs/research/tax-data-tunisia.md § 2.2): each rate's base,
     * FODEC inside it, with its VAT; what no VAT taxes is one more part at no rate.
     *
     * @return list<WithholdingOperation>
     */
    public function getWithholdingOperations(): array
    {
        $operations = [];
        $excluding = Decimal::of($this->amountNet);
        $vatBases = Decimal::zero();
        foreach ($this->getTaxes() as $tax) {
            if (TaxKind::PercentageLine !== $tax->getKind()) {
                continue;
            }
            if ($tax->entersVatBase()) {
                $excluding = $excluding->add(Decimal::of($tax->getAmount()));
                continue;
            }
            $base = Decimal::of($tax->getBase() ?? '0');
            $vatBases = $vatBases->add($base);
            $operations[] = new WithholdingOperation($tax->getRate(), self::stored($base), $tax->getAmount(), self::stored($base->add(Decimal::of($tax->getAmount()))));
        }
        $untaxed = $excluding->sub($vatBases);
        if ($untaxed->compare(0) > 0) {
            $operations[] = new WithholdingOperation(null, self::stored($untaxed), '0.000', self::stored($untaxed));
        }

        return $operations;
    }

    /** The percentage withheld from the supplier at payment, three decimals; null when nothing was. */
    public function getWithholdingRate(): ?string
    {
        return $this->withholdingRate;
    }

    /** What was withheld from the supplier at payment; null when nothing was. */
    public function getWithholdingAmount(): ?string
    {
        return $this->withholdingAmount;
    }

    /** What the payment is to the TEJ platform; null when nobody said. */
    public function getWithholdingOperationCode(): ?TejOperationCode
    {
        return $this->withholdingOperationCode;
    }

    /** What the supplier is handed: the gross less what was withheld. */
    public function getAmountPaid(): string
    {
        return null === $this->withholdingAmount ? $this->amountGross : self::stored(Decimal::of($this->amountGross)->sub(Decimal::of($this->withholdingAmount)));
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethod;
    }

    public function getPaidOn(): ?\DateTimeImmutable
    {
        return $this->paidOn;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
