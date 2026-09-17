<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Module\Expenses\Application\ExpenseInput;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseDetails;
use App\Module\Expenses\Domain\InvalidExpense;
use App\Shared\Domain\PaymentMethod;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's expenses (docs/SPEC.md § 4 expense). Read with expense.read; drafted, revised, deleted, recorded and paid
 * with expense.write. A draft alone is revised or deleted and a recorded expense alone is paid (409 otherwise). The
 * shape is checked here, the company's vendors, categories and taxes by the use case. Amounts are decimal strings at
 * the currency's scale; the tax rate keeps its three decimals.
 */
#[ApiResource(
    shortName: 'Expense',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/expenses',
            provider: ExpenseCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            // One page at a time, with its total, which only JSON-LD carries.
            outputFormats: ['jsonld' => ['application/ld+json']],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in what the expense is for or in the vendor\'s reference on it, whatever their case and accents; under three characters, the exact reference only. The vendor\'s and the category\'s own names are not searched: narrow by vendorId or categoryId instead.'),
                'status' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['draft', 'recorded', 'paid']]),
                'vendorId' => new QueryParameter(schema: self::ID),
                'categoryId' => new QueryParameter(schema: self::ID),
                'order[date]' => new QueryParameter(schema: self::DIRECTION),
                'order[description]' => new QueryParameter(schema: self::DIRECTION),
                'order[vendor]' => new QueryParameter(schema: self::DIRECTION, description: 'By the vendor\'s current name; an expense with no vendor comes last whichever the direction.'),
                'order[category]' => new QueryParameter(schema: self::DIRECTION, description: 'By the category\'s own name, not its parents\'; an expense with no category comes last whichever the direction.'),
                'order[amountGross]' => new QueryParameter(schema: self::DIRECTION),
                'order[status]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}',
            provider: ExpenseItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/expenses',
            processor: CreateExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}',
            processor: ReviseExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}',
            processor: DeleteExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}/record',
            status: 200,
            processor: RecordExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}/pay',
            status: 200,
            processor: PayExpenseProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::PAY]],
            validationContext: ['groups' => [self::PAY]],
        ),
    ],
)]
final class ExpenseResource
{
    public const string READ = 'expense:read';
    public const string WRITE = 'expense:write';
    public const string PAY = 'expense:pay';

    /** Which way one of the list's sorts reads. */
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];

    /** A parameter naming a row of another table. The format is what refuses anything else, with a 422. */
    private const array ID = ['type' => 'string', 'format' => 'uuid'];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** draft, recorded or paid. */
    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['draft', 'recorded', 'paid']])]
    #[Groups([self::READ])]
    public string $status = 'draft';

    /** The day of the expense, YYYY-MM-DD. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $date = '';

    /** The vendor's own reference, such as its invoice number. */
    #[Assert\Length(max: ExpenseDetails::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $reference = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: ExpenseDetails::DESCRIPTION_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $description = '';

    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $vendorId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $vendorName = null;

    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $categoryId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $categoryName = null;

    /** Above 0, at most the currency's decimals. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $amountNet = '';

    /** A rate on the net: VAT or a levy. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $taxComponentId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $taxRate = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $taxAmount = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $amountGross = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $currency = '';

    /** The vendor's payment terms counted from the expense's day, YYYY-MM-DD; null without them. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $dueDate = null;

    #[ApiProperty(schema: ['type' => ['string', 'null'], 'enum' => ['transfer', 'cash', 'check', 'card', 'other', null]])]
    #[Assert\NotBlank(groups: [self::PAY])]
    #[Assert\Choice(callback: [self::class, 'methods'], groups: [self::PAY])]
    #[Groups([self::READ, self::PAY])]
    public ?string $paymentMethod = null;

    /** YYYY-MM-DD, from the expense's day to today. */
    #[Assert\NotBlank(groups: [self::PAY])]
    #[Assert\Date(groups: [self::PAY])]
    #[Groups([self::READ, self::PAY])]
    public ?string $paidOn = null;

    #[Assert\Length(max: ExpenseDetails::NOTES_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notes = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $attachmentCount = 0;

    public static function of(Expense $expense, int $currencyScale, int $attachmentCount): self
    {
        $amount = static fn (string $stored): string => Decimal::format(Decimal::of($stored), $currencyScale);
        $resource = new self();
        $resource->id = $expense->getId()->toRfc4122();
        $resource->status = $expense->getStatus()->value;
        $resource->date = $expense->getDate()->format('Y-m-d');
        $resource->reference = $expense->getReference();
        $resource->description = $expense->getDescription();
        $resource->vendorId = $expense->getVendor()?->getId()->toRfc4122();
        $resource->vendorName = $expense->getVendor()?->getProfile()->name;
        $resource->categoryId = $expense->getCategory()?->getId()->toRfc4122();
        $resource->categoryName = $expense->getCategory()?->getName();
        $resource->amountNet = $amount($expense->getAmountNet());
        $resource->taxComponentId = $expense->getTaxComponent()?->getId()->toRfc4122();
        $resource->taxRate = $expense->getTaxRate();
        $resource->taxAmount = $amount($expense->getTaxAmount());
        $resource->amountGross = $amount($expense->getAmountGross());
        $resource->currency = $expense->getCurrency();
        $resource->dueDate = $expense->getDueDate()?->format('Y-m-d');
        $resource->paymentMethod = $expense->getPaymentMethod()?->value;
        $resource->paidOn = $expense->getPaidOn()?->format('Y-m-d');
        $resource->notes = $expense->getNotes();
        $resource->attachmentCount = $attachmentCount;

        return $resource;
    }

    /** @throws InvalidExpense */
    public function input(): ExpenseInput
    {
        $uuid = static fn (?string $id): ?Uuid => null === $id ? null : Uuid::fromString($id);

        return new ExpenseInput(
            new ExpenseDetails(new \DateTimeImmutable($this->date, new \DateTimeZone('UTC')), $this->description, $this->amountNet, $this->reference, $this->notes),
            $uuid($this->vendorId),
            $uuid($this->categoryId),
            $uuid($this->taxComponentId),
        );
    }

    /** @return list<string> */
    public static function methods(): array
    {
        return array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases());
    }
}
