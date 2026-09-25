<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Domain;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxKind;
use App\Module\Vendors\Domain\Vendor;
use App\Shared\Domain\CompanyOwned;
use App\Shared\Domain\PaymentMethod;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Money a company spent (docs/SPEC.md § 4 expense): a net amount in its currency, taxed at most by one rate on the net,
 * from a vendor, under a category. The tax is the rate on the net rounded half away from zero at the currency's scale
 * and the gross is their sum, both stored as they were worked out. A draft is revised or deleted; a recorded expense is
 * what the books rest on and only gets paid, on a day from its own to today.
 */
#[ORM\Entity]
#[ORM\Table(name: 'expense')]
#[ORM\Index(name: 'idx_expense_company_date', columns: ['company_id', 'expense_date'])]
#[ORM\Index(name: 'idx_expense_vendor', columns: ['vendor_id'])]
#[ORM\Index(name: 'idx_expense_category', columns: ['category_id'])]
#[ORM\Index(name: 'idx_expense_tax_component', columns: ['tax_component_id'])]
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

    #[ORM\ManyToOne(targetEntity: ExpenseCategory::class)]
    #[ORM\JoinColumn(name: 'category_id', nullable: true)]
    private ?ExpenseCategory $category = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $amountNet;

    #[ORM\ManyToOne(targetEntity: TaxComponent::class)]
    #[ORM\JoinColumn(name: 'tax_component_id', nullable: true)]
    private ?TaxComponent $taxComponent = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 3, nullable: true)]
    private ?string $taxRate = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 3)]
    private string $taxAmount;

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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->currency = $company->getCurrency();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * @param int $currencyScale the company currency's decimals
     *
     * @throws InvalidExpense
     */
    public static function create(Company $company, ExpenseDetails $details, ?Vendor $vendor, ?ExpenseCategory $category, ?TaxComponent $tax, int $currencyScale, \DateTimeImmutable $now): self
    {
        $expense = new self($company, $now);
        $expense->apply($details, $vendor, $category, $tax, $currencyScale);

        return $expense;
    }

    /**
     * @return list<string> the fields that changed, none when the revision says what the expense already says
     *
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function revise(ExpenseDetails $details, ?Vendor $vendor, ?ExpenseCategory $category, ?TaxComponent $tax, int $currencyScale, \DateTimeImmutable $now): array
    {
        $this->assertDraft('revised');
        $before = $this->state();
        $this->apply($details, $vendor, $category, $tax, $currencyScale);
        $after = $this->state();

        $changed = array_keys(array_filter($after, static fn (mixed $value, string $field) => $value !== $before[$field], \ARRAY_FILTER_USE_BOTH));
        if (\in_array('taxComponentId', $changed, true)) {
            // A rate is named on its own only when the same component's rate moved since the draft was written.
            $changed = array_values(array_diff($changed, ['taxRate']));
        }
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
            throw new InvalidExpense('categoryId', 'An expense is recorded under a category.');
        }
        $this->status = ExpenseStatus::Recorded;
        $this->updatedAt = $now;
    }

    /**
     * @param \DateTimeImmutable $today           the company's own day
     * @param string|null        $withholdingRate the percentage withheld from the supplier, 0 to 100 with at most three
     *                                            decimals; null or 0 for none
     * @param TejOperationCode|null $operationCode what the payment is to the TEJ platform, kept whatever the rate:
     *                                            a supplier exempt from withholding is declared at 0 %
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
            throw new InvalidExpense('paidOn', \sprintf('An expense is paid on or after its own day, %s.', $this->date->format('Y-m-d')));
        }
        if ($day > self::day($today)) {
            throw new InvalidExpense('paidOn', 'A payment is recorded once it happened, today at the latest.');
        }
        $this->assertDeclarableToTej($operationCode);
        $rate = trim($withholdingRate ?? '');
        if ('' !== $rate && (1 !== preg_match(self::WITHHOLDING_RATE, $rate) || Decimal::of($rate)->compare(100) > 0)) {
            throw new InvalidExpense('withholdingRate', 'A withholding is a percentage from 0 to 100 with at most three decimals.');
        }
        if ('' === $rate || 0 === Decimal::of($rate)->compare(0)) {
            $this->withholdingRate = null;
            $this->withholdingAmount = null;
        } else {
            $withheld = Decimal::round(Decimal::of($this->amountGross)->mul(Decimal::of($rate))->div(100, Decimal::WORKING_SCALE), $currencyScale);
            $this->withholdingRate = Decimal::format(Decimal::of($rate), 3);
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
        if (null !== $operationCode && TejOperationCode::PRESET !==$this->company->getFiscalPreset()) {
            throw new InvalidExpense('withholdingOperationCode', 'A TEJ operation code is said for a company under the Tunisian preset only.');
        }
    }

    /** @throws ExpenseTransitionRefused when the expense is no longer a draft */
    public function assertDraft(string $action): void
    {
        if (ExpenseStatus::Draft !== $this->status) {
            throw new ExpenseTransitionRefused(\sprintf('The expense is %s: only a draft is %s.', $this->status->value, $action));
        }
    }

    /** The vendor's payment terms counted from the expense's day; none without a vendor or its terms. */
    public function getDueDate(): ?\DateTimeImmutable
    {
        $days = $this->vendor?->getProfile()->paymentTermsDays;

        return null === $days ? null : $this->date->modify(\sprintf('+%d days', $days));
    }

    private function apply(ExpenseDetails $details, ?Vendor $vendor, ?ExpenseCategory $category, ?TaxComponent $tax, int $currencyScale): void
    {
        $net = Decimal::of($details->amountNet);
        if (0 !== Decimal::round($net, $currencyScale)->compare($net)) {
            throw new InvalidExpense('amountNet', \sprintf('A net amount carries at most %d decimals, the currency\'s.', $currencyScale));
        }
        if (null !== $vendor && (!$vendor->getCompany()->getId()->equals($this->company->getId()) || (!$vendor->isActive() && $vendor !== $this->vendor))) {
            throw new InvalidExpense('vendorId', 'An expense names an active vendor of its company.');
        }
        if (null !== $category && (!$category->getCompany()->getId()->equals($this->company->getId()) || (!$category->isActive() && $category !== $this->category))) {
            throw new InvalidExpense('categoryId', 'An expense is filed under an active category of its company.');
        }
        if (null !== $tax && (!$tax->getCompany()->getId()->equals($this->company->getId()) || (!$tax->isActive() && $tax !== $this->taxComponent) || TaxKind::PercentageLine !== $tax->getKind())) {
            throw new InvalidExpense('taxComponentId', 'An expense is taxed by an active rate on the net of its company.');
        }

        $rate = $tax?->getRate();
        $taxAmount = null === $rate ? Decimal::zero() : Decimal::round($net->mul(Decimal::of($rate))->div(100, Decimal::WORKING_SCALE), $currencyScale);

        $this->date = $details->date;
        $this->reference = $details->reference;
        $this->description = $details->description;
        $this->vendor = $vendor;
        $this->category = $category;
        $this->amountNet = self::stored($net);
        $this->taxComponent = $tax;
        $this->taxRate = $rate;
        $this->taxAmount = self::stored($taxAmount);
        $this->amountGross = self::stored($net->add($taxAmount));
        $this->notes = $details->notes;
    }

    /** @return array<string, mixed> every revisable value by the field a revision names */
    private function state(): array
    {
        return [
            'date' => $this->date->format('Y-m-d'),
            'reference' => $this->reference,
            'description' => $this->description,
            'amountNet' => $this->amountNet,
            'vendorId' => $this->vendor?->getId()->toRfc4122(),
            'categoryId' => $this->category?->getId()->toRfc4122(),
            'taxComponentId' => $this->taxComponent?->getId()->toRfc4122(),
            'taxRate' => null === $this->taxComponent ? null : $this->taxRate,
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

    public function getTaxComponent(): ?TaxComponent
    {
        return $this->taxComponent;
    }

    /** The rate the tax was worked out at, three decimals; null without a tax. */
    public function getTaxRate(): ?string
    {
        return $this->taxRate;
    }

    /** Three decimals. */
    public function getTaxAmount(): string
    {
        return $this->taxAmount;
    }

    /** Three decimals. */
    public function getAmountGross(): string
    {
        return $this->amountGross;
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
