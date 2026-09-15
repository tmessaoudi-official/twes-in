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
     * @param \DateTimeImmutable $today the company's own day
     *
     * @throws ExpenseTransitionRefused
     * @throws InvalidExpense
     */
    public function pay(PaymentMethod $method, \DateTimeImmutable $paidOn, \DateTimeImmutable $today, \DateTimeImmutable $now): void
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

        $this->paymentMethod = $method;
        $this->paidOn = $day;
        $this->status = ExpenseStatus::Paid;
        $this->updatedAt = $now;
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
