<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Fiscal\Infrastructure\ApiPlatform\DocumentPreview;
use App\Module\Invoices\Application\InvoiceInput;
use App\Module\Invoices\Application\InvoiceLineInput;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceFigures;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceTax;
use App\Module\Invoices\Domain\OperationCategory;
use App\Module\Invoices\Domain\Payment;
use App\Module\Products\Domain\LotCode;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's invoices and credit notes (docs/SPEC.md § 4 invoice). Read with invoice.read, drafted, revised and
 * cancelled with invoice.write; a document that is no longer a draft answers 409 to a revision or a cancellation. The
 * shape is checked here, the company's customers, products, units and taxes by the use case. A draft's figures are
 * worked out on every read: amounts are decimal strings at the currency's scale, quantities with three decimals,
 * prices with four, rates with three.
 */
#[ApiResource(
    shortName: 'Invoice',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/invoices',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: InvoiceCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the number, the customer\'s reference or the customer as the document recorded them, whatever their case and accents; under three characters, the exact number only. A draft carries no number and no recorded customer: narrow it with customerId.'),
                'status[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['draft', 'issued', 'partially_paid', 'paid', 'cancelled', 'overdue']]], description: 'Several statuses, OR\'d: `status[]=draft&status[]=overdue`; a single `status=draft` is the same filter with one value. `overdue` is what the status column shows rather than a status a document holds: an invoice, issued or partly paid, whose due day has passed in the company\'s own timezone.', constraints: []),
                'documentType[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['invoice', 'deposit', 'credit_note']]], description: 'Several kinds of document, OR\'d; a single `documentType=invoice` still works. `deposit` is a facture d\'acompte and `invoice` every other invoice.', constraints: []),
                'customerId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'Several customers, OR\'d; a single `customerId=…` still works.', constraints: []),
                'issueDate[from]' => new QueryParameter(schema: self::DAY, description: 'Issued on or after this day, in the company\'s own calendar. A draft has no issue day, so it is left out by any end.'),
                'issueDate[to]' => new QueryParameter(schema: self::DAY, description: 'Issued on or before this day.'),
                'dueDate[from]' => new QueryParameter(schema: self::DAY, description: 'Due on or after this day.'),
                'dueDate[to]' => new QueryParameter(schema: self::DAY, description: 'Due on or before this day.'),
                'totalGross[min]' => new QueryParameter(schema: self::AMOUNT, description: 'A document total, tax included, of at least this amount.'),
                'totalGross[max]' => new QueryParameter(schema: self::AMOUNT, description: 'A document total, tax included, of at most this amount.'),
                'amountDue[min]' => new QueryParameter(schema: self::AMOUNT, description: 'Still due: at least this amount.'),
                'amountDue[max]' => new QueryParameter(schema: self::AMOUNT, description: 'Still due: at most this amount.'),
                'order[number]' => new QueryParameter(schema: self::DIRECTION, description: 'Drafts carry no number and come last whichever the direction.'),
                'order[customer]' => new QueryParameter(schema: self::DIRECTION, description: 'By the customer\'s current name.'),
                'order[issueDate]' => new QueryParameter(schema: self::DIRECTION),
                'order[dueDate]' => new QueryParameter(schema: self::DIRECTION),
                'order[status]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}',
            provider: InvoiceItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices',
            processor: CreateInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}',
            processor: ReviseInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/preview',
            openapi: new OpenApiOperation(summary: 'What a new invoice with this body would come to, kept nowhere.', description: 'Takes the body a save takes and answers what saving it would come to, through the calculator every document shares: each line, then the totals. Nothing is written. Asks invoice.write (and, for a credit note, the right to draft one), as saving does; refused as saving is, with the field named (422).'),
            status: 200,
            processor: PreviewInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            output: DocumentPreview::class,
            normalizationContext: ['groups' => [DocumentPreview::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/preview',
            openapi: new OpenApiOperation(summary: 'What this draft invoice would come to with this body, kept nowhere.', description: 'Takes the body a save takes and answers what saving it would come to, through the calculator every document shares: each line, then the totals. Nothing is written. Asks invoice.write (and, for a credit note, the right to draft one), as saving does; refused as saving is, with the field named (422), and 409 once the document is no longer a draft.'),
            status: 200,
            processor: PreviewInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: DocumentPreview::class,
            normalizationContext: ['groups' => [DocumentPreview::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/cancel',
            status: 200,
            processor: CancelInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/duplicate',
            processor: DuplicateInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/credit-notes',
            processor: CreateCreditNoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::CREDIT]],
            validationContext: ['groups' => [self::CREDIT]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/issue',
            status: 200,
            processor: IssueInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            parameters: [
                'excessTo' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['balance', 'refund']], description: 'For a credit note of an invoice that was paid beyond what the credit note leaves due: where the part already paid goes, to the customer\'s credit balance or to a refund. Required then (422 on `excessTo`), ignored otherwise.'),
            ],
            normalizationContext: self::NORMALIZATION,
        ),
    ],
)]
final class InvoiceResource
{
    public const string READ = 'invoice:read';
    public const string WRITE = 'invoice:write';
    /** What drafting a credit note takes: its reason, and nothing a revision changes. */
    public const string CREDIT = 'invoice:credit';
    /** Nulls are answered: a draft's absent number and a line without a product read alike. */
    public const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false, AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true];
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];
    private const array ID = ['type' => 'string', 'format' => 'uuid'];
    private const array DAY = ['type' => 'string', 'format' => 'date'];
    /** An amount as a decimal string, never a float. */
    private const array AMOUNT = ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\\.[0-9]{1,4})?$'];
    private const array TEXT_OR_NULL = ['type' => ['string', 'null']];
    private const array AMOUNT_LIST = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'rate', 'base', 'amount'],
            'properties' => ['code' => ['type' => 'string'], 'rate' => ['type' => 'string'], 'base' => ['type' => 'string'], 'amount' => ['type' => 'string']],
        ],
    ];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** An invoice, or a credit note correcting one. */
    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['invoice', 'credit_note']])]
    #[Groups([self::READ])]
    public string $type = 'invoice';

    /** The invoice a credit note corrects; null for an invoice. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $correctsInvoiceId = null;

    /** A facture d'acompte: an invoice of part of an operation, given back on the invoice of the whole. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $deposit = false;

    /** The quote it was drafted from, a deposit or the invoice of the whole; null otherwise. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $quoteId = null;

    /** Why a credit note corrects its invoice, stated when it is drafted and printed on it; null for an invoice. */
    #[Assert\NotBlank(normalizer: 'trim', groups: [self::CREDIT])]
    #[Assert\Length(max: Invoice::CREDIT_NOTE_REASON_MAX, groups: [self::CREDIT])]
    #[Groups([self::READ, self::CREDIT])]
    public ?string $creditNoteReason = null;

    /** The number issuing gives the document; null while it is a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $number = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['draft', 'issued', 'partially_paid', 'paid', 'cancelled']])]
    #[Groups([self::READ])]
    public string $status = 'draft';

    /** One of the company's active customers (GET .../invoice-options/customers?q=). */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $customerId = '';

    /**
     * Who the document is for, in the customer's current words. It travels WITH the document because a form that
     * opens one must show who it names without being handed the company's whole book of customers to look it up in
     * (docs/SPEC.md § 7, 2026-09-17, ruling 3). What was PRINTED on an issued document is the recorded customer, not
     * this — this is the customer as it reads today.
     */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $customerName = '';

    /** One of the company's establishments; left out, the default one. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $establishmentId = null;

    /** The day issuing numbered the document, in the company's time zone; null while it is a draft. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $issueDate = null;

    /** The day the goods or services were supplied, YYYY-MM-DD. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $supplyDate = null;

    /** Days from the issue day to the due day, 0 to 365; left out, the customer's at issue. */
    #[ApiProperty(schema: ['type' => ['integer', 'null'], 'minimum' => 0, 'maximum' => InvoiceHeader::TERMS_MAX])]
    #[Groups([self::READ, self::WRITE])]
    public ?int $paymentTermsDays = null;

    /** The customer's own reference for the order, such as a purchase order number. */
    #[Assert\Length(max: InvoiceHeader::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $customerReference = null;

    /** Printed on the document. */
    #[Assert\Length(max: InvoiceHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notesPrinted = null;

    /** Kept inside the company, never printed. */
    #[Assert\Length(max: InvoiceHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notesInternal = null;

    /** An amount off the whole document, spread over its lines; at most what the lines come to. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'example' => '100.000'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $discountAmount = null;

    /**
     * What its operations are, where its country's law asks (docs/fiscal/FR.md § 4a): `goods`, `services` or `both`. On a
     * draft the category chosen, null to have it worked out at issue from its lines' products; on an issued document the
     * one it states. A company whose law asks none refuses it.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'enum' => ['goods', 'services', 'both', null]])]
    #[Assert\Choice(choices: ['goods', 'services', 'both'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $operationCategory = null;

    /** Whether the company had opted to pay VAT on the débits when it was issued; null on a draft and where the law asks nothing. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?bool $vatOnDebits = null;

    /**
     * The fixed charges and withholdings on the whole document, in order; left out (null), the company's defaults and
     * the customer's own that its regime charges; an empty list for none.
     *
     * @var list<string>|null
     */
    #[ApiProperty(schema: ['type' => ['array', 'null'], 'items' => self::ID])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?array $documentTaxComponentIds = null;

    /**
     * The lines, in order. A line naming a product may leave out its description, unit, price and taxes (null), which
     * then come from the product; a line of a draft drafted from delivery notes names the delivery note line it invoices,
     * which a revision may keep or drop but never add; a line of a product tracked by lot or serial may name the one
     * sold (docs/SPEC.md § 7, 2026-09-24 12:40 row 5); a credit note's line may say its goods came back to stock; a line
     * naming a deposit invoice gives it back, written by the API from the deposit whatever else it says; `net` is
     * answered, never read: the line after its own discount.
     *
     * @var list<array<string, mixed>>
     */
    #[ApiProperty(schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['quantity'],
            'properties' => [
                'productId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'productReference' => ['type' => ['string', 'null'], 'description' => 'The product as it reads today, so a form shows the line without the catalogue. Read only.'],
                'productName' => ['type' => ['string', 'null'], 'description' => 'The product as it reads today. Read only.'],
                'description' => ['type' => ['string', 'null'], 'maxLength' => InvoiceLineDetails::DESCRIPTION_MAX],
                'quantity' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '2.5'],
                'unitId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'unitPriceNet' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$', 'example' => '1250.5000'],
                'discountRate' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,2})(\.[0-9]{1,3})?$', 'example' => '10', 'description' => 'A percentage of the line; null when the line has no discount or one given as an amount.'],
                'discountAmount' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '150', 'description' => 'The line\'s whole discount as an amount, at most the line and at the currency\'s scale, in place of a rate: a line sending both is refused on `lines[i].discountAmount`. It stays the line\'s whatever its quantity.'],
                'taxComponentIds' => ['type' => ['array', 'null'], 'items' => self::ID],
                'sourceDeliveryNoteLineId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'sourceLeft' => ['type' => ['string', 'null'], 'readOnly' => true, 'description' => 'On a draft invoice\'s line taken from a delivery note: the most it may invoice, what the note line delivered less what the company\'s other invoices that are not cancelled take. Null otherwise.'],
                'productTracking' => ['type' => ['string', 'null'], 'enum' => ['none', 'lot', 'serial', null], 'description' => 'How the product\'s stock is told apart today, so a form knows whether the line names a lot. Read only.'],
                'lotCode' => ['type' => ['string', 'null'], 'maxLength' => LotCode::MAX, 'description' => 'The lot or serial sold, for a product tracked by one.'],
                'returned' => ['type' => 'boolean', 'description' => 'On a credit note\'s line: its goods came back to stock when the note is issued. A credit note says so line by line, since a price correction returns nothing; any other document refuses it.'],
                'deductsInvoiceId' => ['type' => ['string', 'null'], 'format' => 'uuid', 'description' => 'A deposit invoice of the customer this line gives back, net and taxes as the deposit charged them. Sent on one line, it is written as one line per line of the deposit, from the deposit; what else the line says is not read. Null on any other line.'],
                'net' => ['type' => 'string', 'readOnly' => true],
                'unitCost' => ['type' => ['string', 'null'], 'readOnly' => true, 'description' => 'What one unit of its product cost the company when the line was issued; null on a draft, when unknown, and for a caller without product.cost.read.'],
            ],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(fields: [
        'productId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'description' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Length(max: InvoiceLineDetails::DESCRIPTION_MAX, groups: [self::WRITE])], groups: [self::WRITE]),
        'quantity' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'unitId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'unitPriceNet' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'discountRate' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'discountAmount' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'taxComponentIds' => new Assert\Optional([
            new Assert\Type('list', groups: [self::WRITE]),
            new Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        ], groups: [self::WRITE]),
        'sourceDeliveryNoteLineId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'lotCode' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'returned' => new Assert\Optional([new Assert\Type('bool', groups: [self::WRITE])], groups: [self::WRITE]),
        'deductsInvoiceId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
    ], allowExtraFields: true, groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $lines = [];

    /** The lines' amounts after their own discounts, added up. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $subtotalNet = '0';

    /** The document discount, as the currency counts it. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $documentDiscount = '0';

    /** What the line discounts and the document discount take off together; null on a document issued before it was kept. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $savings = null;

    /** The subtotal less the document discount: the base of the taxes. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $totalNet = '0';

    /** @var list<array{code: string, rate: string, base: string, amount: string}> each line tax over the lines carrying it */
    #[ApiProperty(writable: false, schema: self::AMOUNT_LIST)]
    #[Groups([self::READ])]
    public array $taxes = [];

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $totalTax = '0';

    /** @var list<array{code: string, amount: string}> the fixed charges, outside every tax base */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => ['type' => 'object', 'required' => ['code', 'amount'], 'properties' => ['code' => ['type' => 'string'], 'amount' => ['type' => 'string']]],
    ])]
    #[Groups([self::READ])]
    public array $fixedTaxes = [];

    /** The net after the document discount, the taxes and the fixed charges. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $total = '0';

    /** @var list<array{code: string, rate: string, base: string, amount: string}> the withholdings whose threshold the document reaches */
    #[ApiProperty(writable: false, schema: self::AMOUNT_LIST)]
    #[Groups([self::READ])]
    public array $withholdings = [];

    /** The total less what is withheld at source: « net à payer », what the paid, credited and left amounts add up to. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $netToPay = '0';

    /** The total less what is withheld, paid and credited: what the customer still owes. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $amountDue = '0';

    /** What payments recorded on the invoice come to. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $amountPaid = '0';

    /** What issued credit notes took off the invoice. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $amountCredited = '0';

    /**
     * The payments recorded on the invoice, by day (POST .../payments, DELETE .../payments/{paymentId}).
     *
     * @var list<array{id: string, date: string, amount: string, method: string, reference: string|null, notes: string|null, recordedBy: string|null, createdAt: string}>
     */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['id', 'date', 'amount', 'method', 'reference', 'notes', 'recordedBy', 'createdAt'],
            'properties' => [
                'id' => self::ID,
                'date' => ['type' => 'string', 'format' => 'date'],
                'amount' => ['type' => 'string'],
                'method' => ['type' => 'string', 'enum' => ['transfer', 'cash', 'check', 'card', 'other']],
                'reference' => self::TEXT_OR_NULL,
                'notes' => self::TEXT_OR_NULL,
                'recordedBy' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'createdAt' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $payments = [];

    /** The issue day plus the terms; null while it is a draft. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $dueDate = null;

    /** The language the document prints in, fixed at issue; null while it is a draft. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'enum' => ['fr', 'en', null]])]
    #[Groups([self::READ])]
    public ?string $language = null;

    /**
     * What the customer was called the day the document was issued; null while it is a draft.
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(writable: false, schema: [
        'type' => ['object', 'null'],
        'required' => ['number', 'kind', 'name', 'legalName', 'identifiers', 'billingAddress', 'taxRegimeCode', 'taxMentionKey'],
        'properties' => [
            'number' => ['type' => 'string'],
            'kind' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'legalName' => self::TEXT_OR_NULL,
            'identifiers' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            'billingAddress' => [
                'type' => 'object',
                'required' => ['line1', 'line2', 'postalCode', 'city', 'countryCode'],
                'properties' => ['line1' => self::TEXT_OR_NULL, 'line2' => self::TEXT_OR_NULL, 'postalCode' => self::TEXT_OR_NULL, 'city' => self::TEXT_OR_NULL, 'countryCode' => self::TEXT_OR_NULL],
            ],
            'taxRegimeCode' => ['type' => 'string'],
            'taxMentionKey' => self::TEXT_OR_NULL,
        ],
    ])]
    #[Groups([self::READ])]
    public ?array $customerSnapshot = null;

    /** @var list<string> the translation keys of the legal mentions it prints, fixed at issue; none on a draft */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $mentions = [];

    /** The company's late penalty text as it read at issue; null when it had none or on a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $latePenaltyText = null;

    /** The company's invoice footer as it read at issue; null when it had none or on a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $footer = null;

    /**
     * @param bool                  $withCosts  whether the caller may read costs (product.cost.read), which each issued line froze
     * @param array<string, string> $sourceRoom what a draft's lines may take of their delivery note lines, by note line id
     */
    public static function of(Invoice $invoice, InvoiceFigures $figures, bool $withCosts = false, array $sourceRoom = []): self
    {
        $header = $invoice->getHeader();
        $resource = new self();
        $resource->id = $invoice->getId()->toRfc4122();
        $resource->type = $invoice->getType()->value;
        $resource->correctsInvoiceId = $invoice->getCorrectedInvoice()?->getId()->toRfc4122();
        $resource->deposit = $invoice->isDeposit();
        $resource->quoteId = $invoice->getQuoteId()?->toRfc4122();
        $resource->creditNoteReason = $invoice->getCreditNoteReason();
        $resource->number = $invoice->getNumber();
        $resource->status = $invoice->getStatus()->value;
        $resource->customerId = $invoice->getCustomer()->getId()->toRfc4122();
        $resource->customerName = $invoice->getCustomer()->getProfile()->name;
        $resource->establishmentId = $invoice->getEstablishment()->getId()->toRfc4122();
        $resource->issueDate = $invoice->getIssueDate()?->format('Y-m-d');
        $resource->supplyDate = $header->supplyDate?->format('Y-m-d');
        $resource->paymentTermsDays = $header->paymentTermsDays;
        $resource->customerReference = $header->customerReference;
        $resource->notesPrinted = $header->notesPrinted;
        $resource->notesInternal = $header->notesInternal;
        $resource->discountAmount = $header->discountAmount;
        $resource->operationCategory = ($invoice->getOperationCategory() ?? $header->operationCategory)?->value;
        $resource->vatOnDebits = $invoice->getVatOnDebits();
        $resource->documentTaxComponentIds = array_map(static fn (InvoiceTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $invoice->getDocumentTaxes());
        $resource->lines = array_map(static fn (InvoiceLine $line, array $fixed): array => [
            'productId' => $line->getProduct()?->getId()->toRfc4122(),
            'productReference' => $line->getProduct()?->getReference(),
            'productName' => $line->getProduct()?->getDetails()->name,
            'description' => $line->getDescription(),
            'quantity' => $line->getQuantity(),
            'unitId' => $line->getUnit()->getId()->toRfc4122(),
            'unitPriceNet' => $line->getUnitPriceNet(),
            'discountRate' => $line->getDiscountRate(),
            'discountAmount' => $line->getDiscountAmount(),
            'taxComponentIds' => array_map(static fn (InvoiceLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $line->getTaxes()),
            'sourceDeliveryNoteLineId' => $line->getSourceDeliveryNoteLineId()?->toRfc4122(),
            'sourceLeft' => $sourceRoom[$line->getSourceDeliveryNoteLineId()?->toRfc4122() ?? ''] ?? null,
            'productTracking' => $line->getProduct()?->getTracking()->value,
            'lotCode' => $line->getLotCode(),
            'returned' => $line->isReturned(),
            'deductsInvoiceId' => $line->getDeduction()?->deposit->getId()->toRfc4122(),
            'net' => $fixed['net'],
            'unitCost' => $withCosts ? $line->getUnitCost() : null,
        ], $invoice->getLines(), $figures->lines);
        $resource->subtotalNet = $figures->subtotalNet;
        $resource->documentDiscount = $figures->documentDiscount;
        $resource->savings = $figures->savings;
        $resource->totalNet = $figures->totalNet;
        $resource->taxes = $figures->taxes;
        $resource->totalTax = $figures->totalTax;
        $resource->fixedTaxes = $figures->fixedTaxes;
        $resource->total = $figures->total;
        $resource->withholdings = $figures->withholdings;
        $resource->netToPay = $figures->netToPay();
        $resource->amountDue = $figures->amountDue;
        $resource->amountPaid = $figures->amountPaid;
        $resource->amountCredited = $figures->amountCredited;
        $resource->payments = array_map(static function (Payment $payment): array {
            $row = PaymentResource::of($payment);

            return ['id' => (string) $row->id, 'date' => $row->date, 'amount' => $row->amount, 'method' => $row->method, 'reference' => $row->reference, 'notes' => $row->notes, 'recordedBy' => $row->recordedBy, 'createdAt' => $row->createdAt];
        }, $invoice->getPayments());
        $resource->dueDate = $invoice->getDueDate()?->format('Y-m-d');
        $resource->language = $invoice->getLanguage();
        $snapshot = $invoice->getCustomerSnapshot();
        $resource->customerSnapshot = null === $snapshot ? null : ['identifiers' => new \ArrayObject($snapshot->identifiers)] + $snapshot->toArray();
        $resource->mentions = $invoice->getMentionKeys();
        $resource->latePenaltyText = $invoice->getLatePenaltyText();
        $resource->footer = $invoice->getFooter();

        return $resource;
    }

    /** @throws InvalidInvoice */
    public function input(): InvoiceInput
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $taxIds = $line['taxComponentIds'] ?? null;
            $lines[] = new InvoiceLineInput(
                self::uuid(self::text($line, 'productId')),
                self::text($line, 'description'),
                self::text($line, 'quantity') ?? '',
                self::uuid(self::text($line, 'unitId')),
                self::text($line, 'unitPriceNet'),
                self::text($line, 'discountRate'),
                \is_array($taxIds) ? self::uuids($taxIds) : null,
                self::uuid(self::text($line, 'sourceDeliveryNoteLineId')),
                self::text($line, 'lotCode'),
                true === ($line['returned'] ?? false),
                self::uuid(self::text($line, 'deductsInvoiceId')),
                self::text($line, 'discountAmount'),
            );
        }

        return new InvoiceInput(
            Uuid::fromString($this->customerId),
            self::uuid($this->establishmentId),
            new InvoiceHeader(
                null === $this->supplyDate ? null : new \DateTimeImmutable($this->supplyDate, new \DateTimeZone('UTC')),
                $this->paymentTermsDays,
                $this->customerReference,
                $this->notesPrinted,
                $this->notesInternal,
                $this->discountAmount,
                null === $this->operationCategory ? null : OperationCategory::from($this->operationCategory),
            ),
            $lines,
            null === $this->documentTaxComponentIds ? null : self::uuids($this->documentTaxComponentIds),
        );
    }

    /**
     * @param array<array-key, mixed> $ids
     *
     * @return list<Uuid>
     */
    private static function uuids(array $ids): array
    {
        return array_values(array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : ''), $ids));
    }

    /** @param array<string, mixed> $line */
    private static function text(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return \is_string($value) ? $value : null;
    }

    private static function uuid(?string $id): ?Uuid
    {
        return null === $id ? null : Uuid::fromString($id);
    }
}
