<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\ValueObject\TaxKind;
use App\Module\Expenses\Application\ExpenseInput;
use App\Module\Expenses\Domain\Exception\InvalidExpense;
use App\Module\Expenses\Domain\Model\Expense;
use App\Module\Expenses\Domain\Model\ExpenseLine;
use App\Module\Expenses\Domain\Model\ExpenseLineTax;
use App\Module\Expenses\Domain\Model\ExpenseTax;
use App\Module\Expenses\Domain\ValueObject\ExpenseAmounts;
use App\Module\Expenses\Domain\ValueObject\ExpenseDetails;
use App\Module\Expenses\Domain\ValueObject\TejOperationCode;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Processor\ClassifyExpenseWithholdingProcessor;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Processor\CreateExpenseProcessor;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Processor\DeleteExpenseProcessor;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Processor\PayExpenseProcessor;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Processor\RecordExpenseProcessor;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Processor\ReviseExpenseProcessor;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Provider\ExpenseCollectionProvider;
use App\Module\Expenses\Infrastructure\ApiPlatform\State\Provider\ExpenseItemProvider;
use App\Shared\Domain\ValueObject\PaymentMethod;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
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
                'status[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['draft', 'recorded', 'paid']]], description: 'Several statuses, OR\'d: `status[]=draft&status[]=paid`; a single `status=draft` is the same filter with one value.', constraints: []),
                'paymentMethod[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['transfer', 'cash', 'check', 'card', 'other']]], description: 'Several ways of paying, OR\'d; a single `paymentMethod=cash` still works. An expense not paid yet has none and is left out by any.', constraints: []),
                'withheld' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['yes', 'no']], description: '`yes`: something was withheld at the source when it was paid; `no`: nothing was, which includes what is not paid yet.'),
                'vendorId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'Several vendors, OR\'d; a single `vendorId=…` still works.', constraints: []),
                'categoryId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'Several categories, OR\'d, each with every category under it at any depth; a single `categoryId=…` still works.', constraints: []),
                'date[from]' => new QueryParameter(schema: self::DAY, description: 'On or after this day.'),
                'date[to]' => new QueryParameter(schema: self::DAY, description: 'On or before this day.'),
                'amountGross[min]' => new QueryParameter(schema: self::AMOUNT, description: 'Taxes included, at least this amount.'),
                'amountGross[max]' => new QueryParameter(schema: self::AMOUNT, description: 'Taxes included, at most this amount.'),
                'order[date]' => new QueryParameter(schema: self::DIRECTION),
                'order[description]' => new QueryParameter(schema: self::DIRECTION),
                'order[vendor]' => new QueryParameter(schema: self::DIRECTION, description: 'By who was paid: the vendor\'s current name, else the payee written on the expense; an expense naming neither comes last whichever the direction.'),
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
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
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
        new Post(
            uriTemplate: '/companies/{companyId}/expenses/{expenseId}/withholding-operation',
            status: 200,
            processor: ClassifyExpenseWithholdingProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::CLASSIFY]],
            validationContext: ['groups' => [self::CLASSIFY]],
            description: 'Says, corrects or, with null, takes back the TEJ operation a paid expense\'s withholding is declared under. A paid expense alone (409 otherwise).',
        ),
    ],
)]
final class ExpenseResource
{
    public const string READ = 'expense:read';
    public const string WRITE = 'expense:write';
    public const string PAY = 'expense:pay';
    public const string CLASSIFY = 'expense:classify';

    /** Which way one of the list's sorts reads. */
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];

    private const array DAY = ['type' => 'string', 'format' => 'date'];
    /** A decimal string, as every amount is: a float would round it. */
    private const array AMOUNT = ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,4})?$'];
    /** A parameter naming a row of another table. The format is what refuses anything else, with a 422. */
    private const array ID = ['type' => 'string', 'format' => 'uuid'];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The version read; a revision sends back the one it was opened at, and one saved since answers 409. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([self::READ, RecordVersion::REVISE])]
    public ?int $version = null;

    /** draft, recorded or paid. */
    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['draft', 'recorded', 'paid']])]
    #[Groups([self::READ])]
    public string $status = 'draft';

    /** The day of the expense, YYYY-MM-DD. */
    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Date(groups: [self::WRITE], payload: ['reason' => 'not_a_date'])]
    #[Groups([self::READ, self::WRITE])]
    public string $date = '';

    /** The vendor's own reference, such as its invoice number. */
    #[Assert\Length(max: ExpenseDetails::REFERENCE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => ExpenseDetails::REFERENCE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $reference = null;

    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Length(max: ExpenseDetails::DESCRIPTION_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => ExpenseDetails::DESCRIPTION_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public string $description = '';

    #[Assert\Uuid(groups: [self::WRITE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $vendorId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $vendorName = null;

    /** Who was paid, in words, when no vendor record names them: a car park, a notary. Never beside a vendor. */
    #[Assert\Length(max: ExpenseDetails::PAYEE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => ExpenseDetails::PAYEE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $payee = null;

    #[Assert\Uuid(groups: [self::WRITE], payload: ['reason' => 'invalid_value'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $categoryId = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $categoryName = null;

    /**
     * The supplier's bill as it prints it (docs/plans/expense-taxes.plan.md), in order: each line a net above 0 at
     * most the currency's decimals and the rates on it, one VAT rate at most and FODEC; a bill at two VAT rates has a
     * line for each. A refusal names `lines[i].amountNet` or `lines[i].taxComponentIds`.
     *
     * @var list<array<string, mixed>>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'minItems' => 1,
        'maxItems' => ExpenseAmounts::LINES_MAX,
        'items' => [
            'type' => 'object',
            'required' => ['amountNet'],
            'properties' => [
                'amountNet' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,3})?$', 'example' => '1000'],
                'taxComponentIds' => ['type' => 'array', 'items' => self::ID, 'description' => 'Rates on the net of the company: one VAT rate at most, and levies such as FODEC, which enters the VAT base.'],
                'taxCodes' => ['type' => 'array', 'items' => ['type' => 'string'], 'readOnly' => true, 'description' => 'The codes the line was taxed under when written.'],
            ],
        ],
    ])]
    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Type('list', groups: [self::WRITE], payload: ['reason' => 'invalid_value'])]
    #[Assert\All([new Assert\Collection(fields: [
        'amountNet' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'taxComponentIds' => new Assert\Optional([
            new Assert\Type('list', groups: [self::WRITE]),
            new Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        ], groups: [self::WRITE]),
    ], allowExtraFields: true, groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $lines = [];

    /**
     * The fixed charges the bill carries, such as the stamp; an empty list for none.
     *
     * @var list<string>
     */
    #[ApiProperty(schema: ['type' => 'array', 'items' => self::ID])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $documentTaxComponentIds = [];

    /**
     * The tax amounts the bill prints where they differ from what is worked out, by tax code: each a rate of the bill,
     * at most one unit of the currency's last decimal a line from what it works out to. Refused on `printedTaxes`.
     *
     * @var list<array<string, mixed>>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'amount'],
            'properties' => ['code' => ['type' => 'string', 'example' => 'TVA19'], 'amount' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,3})?$', 'example' => '191.901']],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(fields: [
        'code' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'amount' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
    ], groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $printedTaxes = [];

    /** What the lines come to excluding taxes. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $amountNet = '';

    /**
     * Each tax the bill charges: the rates in the order printed with their base, then the fixed charges with none;
     * `workedOut` is what twes-in works out, `amount` what the bill charges.
     *
     * @var list<array{code: string, kind: string, rate: string|null, base: string|null, workedOut: string, amount: string}>
     */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'kind', 'rate', 'base', 'workedOut', 'amount'],
            'properties' => [
                'code' => ['type' => 'string'],
                'kind' => ['type' => 'string', 'enum' => ['percentage_line', 'fixed_document']],
                'rate' => ['type' => ['string', 'null']],
                'base' => ['type' => ['string', 'null']],
                'workedOut' => ['type' => 'string'],
                'amount' => ['type' => 'string'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $taxes = [];

    /** What the rates come to, as the bill charges them. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $taxAmount = '';

    /** What the fixed charges come to: the stamp. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $fixedAmount = '';

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

    /**
     * The percentage withheld from the supplier (docs/SPEC.md § 7, 2026-09-24 11:40, RPT-09). Sent when paying: absent or
     * null for the preset's withholding when the expense reaches its threshold, "0" for none, any other for that rate.
     * Read: what the payment withheld, null when nothing was.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'example' => '1'])]
    #[Groups([self::READ, self::PAY])]
    public ?string $withholdingRate = null;

    /** What the payment withheld from the supplier; null when nothing was. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $withholdingAmount = null;

    /**
     * The operation the payment's withholding is declared under on Tunisia's TEJ platform, one of the codes the
     * expense options list (docs/research/tax-data-tunisia.md § 2.2). Sent when paying, or afterwards to
     * `/withholding-operation`; never worked out from the rate. Null when nobody said; the monthly declaration then
     * names the expense as missing one.
     */
    #[ApiProperty(example: 'RS7_000001')]
    #[Assert\Choice(callback: [self::class, 'operationCodes'], groups: [self::PAY, self::CLASSIFY])]
    #[Groups([self::READ, self::PAY, self::CLASSIFY])]
    public ?string $withholdingOperationCode = null;

    /** What the supplier is handed: the gross less what was withheld. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $amountPaid = '';

    /** What paying this recorded expense would withhold unless told otherwise; null for none, and once paid. Read on one expense. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $suggestedWithholdingRate = null;

    #[Assert\Length(max: ExpenseDetails::NOTES_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => ExpenseDetails::NOTES_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notes = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $attachmentCount = 0;

    /** A draft whose amount is to be checked against the bill before it is recorded (« montant à vérifier »). */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $amountToCheck = false;

    public static function of(Expense $expense, int $currencyScale, int $attachmentCount, ?string $suggestedWithholdingRate = null): self
    {
        $amount = static fn (string $stored): string => Decimal::format(Decimal::of($stored), $currencyScale);
        $resource = new self();
        $resource->id = $expense->getId()->toRfc4122();
        $resource->version = $expense->getVersion();
        $resource->status = $expense->getStatus()->value;
        $resource->date = $expense->getDate()->format('Y-m-d');
        $resource->reference = $expense->getReference();
        $resource->description = $expense->getDescription();
        $resource->vendorId = $expense->getVendor()?->getId()->toRfc4122();
        $resource->vendorName = $expense->getVendor()?->getProfile()->name;
        $resource->payee = $expense->getPayee();
        $resource->categoryId = $expense->getCategory()?->getId()->toRfc4122();
        $resource->categoryName = $expense->getCategory()?->getName();
        $resource->lines = array_map(static fn (ExpenseLine $line): array => [
            'amountNet' => $amount($line->getAmountNet()),
            'taxComponentIds' => array_map(static fn (ExpenseLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $line->getTaxes()),
            'taxCodes' => $line->getTaxCodes(),
        ], $expense->getLines());
        $taxes = $expense->getTaxes();
        $resource->documentTaxComponentIds = array_values(array_map(static fn (ExpenseTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), array_filter($taxes, static fn (ExpenseTax $tax): bool => TaxKind::FixedDocument === $tax->getKind())));
        $resource->printedTaxes = array_values(array_map(static fn (ExpenseTax $tax): array => ['code' => $tax->getCode(), 'amount' => $amount($tax->getAmount())], array_filter($taxes, static fn (ExpenseTax $tax): bool => $tax->isPrinted())));
        $resource->taxes = array_map(static fn (ExpenseTax $tax): array => [
            'code' => $tax->getCode(),
            'kind' => $tax->getKind()->value,
            'rate' => $tax->getRate(),
            'base' => null === $tax->getBase() ? null : $amount($tax->getBase()),
            'workedOut' => $amount($tax->getWorkedOutAmount()),
            'amount' => $amount($tax->getAmount()),
        ], $taxes);
        $resource->amountNet = $amount($expense->getAmountNet());
        $resource->taxAmount = $amount($expense->getTaxAmount());
        $resource->fixedAmount = $amount($expense->getFixedAmount());
        $resource->amountGross = $amount($expense->getAmountGross());
        $resource->currency = $expense->getCurrency();
        $resource->dueDate = $expense->getDueDate()?->format('Y-m-d');
        $resource->paymentMethod = $expense->getPaymentMethod()?->value;
        $resource->paidOn = $expense->getPaidOn()?->format('Y-m-d');
        $resource->withholdingRate = $expense->getWithholdingRate();
        $resource->withholdingAmount = null === $expense->getWithholdingAmount() ? null : $amount($expense->getWithholdingAmount());
        $resource->withholdingOperationCode = $expense->getWithholdingOperationCode()?->value;
        $resource->amountPaid = $amount($expense->getAmountPaid());
        $resource->suggestedWithholdingRate = $suggestedWithholdingRate;
        $resource->notes = $expense->getNotes();
        $resource->attachmentCount = $attachmentCount;
        $resource->amountToCheck = $expense->isAmountToCheck();

        return $resource;
    }

    /** @throws InvalidExpense */
    public function input(): ExpenseInput
    {
        $uuid = static fn (?string $id): ?Uuid => null === $id ? null : Uuid::fromString($id);

        $uuids = static fn (mixed $ids): array => \is_array($ids) ? array_values(array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : ''), $ids)) : [];
        $printed = [];
        foreach ($this->printedTaxes as $tax) {
            $printed[\is_string($tax['code'] ?? null) ? $tax['code'] : ''] = \is_string($tax['amount'] ?? null) ? $tax['amount'] : '';
        }

        return new ExpenseInput(
            new ExpenseDetails(new \DateTimeImmutable($this->date, new \DateTimeZone('UTC')), $this->description, $this->reference, $this->notes, $this->payee),
            array_map(static fn (array $line): array => [
                'amountNet' => \is_string($line['amountNet'] ?? null) ? $line['amountNet'] : '',
                'taxComponentIds' => $uuids($line['taxComponentIds'] ?? []),
            ], $this->lines),
            $uuid($this->vendorId),
            $uuid($this->categoryId),
            $uuids($this->documentTaxComponentIds),
            $printed,
        );
    }

    /** @return list<string> */
    public static function methods(): array
    {
        return array_map(static fn (PaymentMethod $method): string => $method->value, PaymentMethod::cases());
    }

    /** @return list<string> */
    public static function operationCodes(): array
    {
        return array_map(static fn (TejOperationCode $code): string => $code->value, TejOperationCode::cases());
    }
}
