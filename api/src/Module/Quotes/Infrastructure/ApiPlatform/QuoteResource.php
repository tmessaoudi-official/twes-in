<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\LineTotals;
use App\Fiscal\Domain\Calculation\TaxTotal;
use App\Fiscal\Infrastructure\ApiPlatform\DocumentPreview;
use App\Module\Quotes\Application\QuoteDeposit;
use App\Module\Quotes\Application\QuoteInput;
use App\Module\Quotes\Application\QuoteLineInput;
use App\Module\Quotes\Domain\InvalidQuote;
use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuoteHeader;
use App\Module\Quotes\Domain\QuoteLine;
use App\Module\Quotes\Domain\QuoteLineDetails;
use App\Module\Quotes\Domain\QuoteLineTax;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's quotes (docs/SPEC.md § 7, 2026-10-07 10:21). Read with quote.read; drafted, revised, sent, answered and
 * cancelled with quote.write; invoiced with quote.write and invoice.write. A quote that is no longer a draft answers
 * 409 to a revision. The shape is checked here, the company's customers, products, units and taxes by the use case.
 * The figures are worked out on every read and never written: amounts are decimal strings at the currency's scale,
 * quantities with three decimals, prices with four.
 */
#[ApiResource(
    shortName: 'Quote',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/quotes',
            provider: QuoteCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            // One page at a time, with its total, which only JSON-LD carries.
            outputFormats: ['jsonld' => ['application/ld+json']],
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the number, the customer\'s reference or the customer as the quote recorded them, whatever their case and accents; under three characters, the exact number only. A draft carries no number and no recorded customer: narrow it with customerId.'),
                'status[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['draft', 'sent', 'accepted', 'refused', 'cancelled']]], description: 'Several statuses, OR\'d: `status[]=draft&status[]=sent`; a single `status=draft` is the same filter with one value.', constraints: []),
                'customerId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => self::ID], description: 'Several customers, OR\'d; a single `customerId=…` still works.', constraints: []),
                'issueDate[from]' => new QueryParameter(schema: self::DAY, description: 'Sent on or after this day. A draft has no issue day, so it is left out by any end.'),
                'issueDate[to]' => new QueryParameter(schema: self::DAY, description: 'Sent on or before this day.'),
                'order[number]' => new QueryParameter(schema: self::DIRECTION, description: 'Drafts carry no number and come last whichever the direction.'),
                'order[customer]' => new QueryParameter(schema: self::DIRECTION, description: 'By the customer\'s current name.'),
                'order[issueDate]' => new QueryParameter(schema: self::DIRECTION),
                'order[validUntil]' => new QueryParameter(schema: self::DIRECTION),
                'order[status]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}',
            provider: QuoteItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes',
            processor: CreateQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}',
            processor: ReviseQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/preview',
            openapi: new OpenApiOperation(summary: 'What a new quote with this body would come to, kept nowhere.', description: 'Takes the body a save takes and answers what saving it would come to, through the calculator every document shares: each line, then the totals. Nothing is written. Asks quote.write, as saving does; refused as saving is, with the field named (422).'),
            status: 200,
            processor: PreviewQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            output: DocumentPreview::class,
            normalizationContext: ['groups' => [DocumentPreview::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/preview',
            openapi: new OpenApiOperation(summary: 'What this draft quote would come to with this body, kept nowhere.', description: 'Takes the body a save takes and answers what saving it would come to, through the calculator every document shares: each line, then the totals. Nothing is written. Asks quote.write, as saving does; refused as saving is, with the field named (422), and 409 once the document is no longer a draft.'),
            status: 200,
            processor: PreviewQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            output: DocumentPreview::class,
            normalizationContext: ['groups' => [DocumentPreview::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/send',
            status: 200,
            processor: SendQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/accept',
            status: 200,
            processor: AnswerQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::ANSWER]],
            validationContext: ['groups' => [self::ANSWER]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/refuse',
            status: 200,
            processor: AnswerQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::ANSWER, self::REFUSE]],
            validationContext: ['groups' => [self::ANSWER, self::REFUSE]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/cancel',
            status: 200,
            processor: CancelQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/deposit-invoices',
            status: 200,
            processor: DepositQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::DEPOSIT]],
            validationContext: ['groups' => [self::DEPOSIT]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/quotes/{quoteId}/invoice',
            status: 200,
            processor: InvoiceQuoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
    ],
)]
final class QuoteResource
{
    public const string READ = 'quote:read';
    public const string WRITE = 'quote:write';
    public const string ANSWER = 'quote:answer';
    public const string REFUSE = 'quote:refuse';
    public const string DEPOSIT = 'quote:deposit';
    /** Nulls are answered: a draft's absent number and a line without a product read alike; no identifiers read `{}`. */
    private const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false, AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true];
    private const array TEXT_OR_NULL = ['type' => ['string', 'null']];
    private const array ID = ['type' => 'string', 'format' => 'uuid'];
    private const array DAY = ['type' => 'string', 'format' => 'date'];
    /** Which way one of the list's sorts reads. */
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The number sending gives the quote; null while it is a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $number = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['draft', 'sent', 'accepted', 'refused', 'cancelled']])]
    #[Groups([self::READ])]
    public string $status = 'draft';

    /** A sent quote past its validity date in the company's day: its price no longer binds, and it may still be accepted. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $expired = false;

    /** One of the company's active customers. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $customerId = '';

    /** Who the quote is for, in the customer's current words. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $customerName = '';

    /** One of the company's establishments; left out, the default one. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $establishmentId = null;

    /**
     * What the customer was called, where it was billed and its tax regime, the day the quote was sent; null before.
     *
     * @var array<string, mixed>|null
     */
    #[ApiProperty(writable: false, schema: [
        'type' => ['object', 'null'],
        'required' => ['number', 'kind', 'name', 'legalName', 'identifiers', 'billingAddress', 'taxRegimeCode', 'taxMentionKey'],
        'properties' => [
            'number' => ['type' => 'string'],
            'kind' => ['type' => 'string', 'enum' => ['company', 'individual']],
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

    /** The day sending numbered the quote, in the company's time zone; null while it is a draft. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $issueDate = null;

    /** The last day the quote's price binds; null while it is a draft. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $validUntil = null;

    /**
     * The day the customer agreed or declined, YYYY-MM-DD, from the issue day to the company's today; left out, today.
     * Sent to accept or refuse, answered once it is.
     */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\Date(groups: [self::ANSWER])]
    #[Groups([self::READ, self::ANSWER])]
    public ?string $answeredOn = null;

    /** Why the customer declined, when the company was told. Sent to refuse. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'maxLength' => Quote::REASON_MAX])]
    #[Assert\Length(max: Quote::REASON_MAX, groups: [self::REFUSE])]
    #[Groups([self::READ, self::REFUSE])]
    public ?string $refusalReason = null;

    /** The draft invoice an accepted quote was drafted into (POST .../invoice); null until it is. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'uuid'])]
    #[Groups([self::READ])]
    public ?string $invoiceId = null;

    /**
     * The deposit invoices drawn from the quote (POST .../deposit-invoices), cancelled drafts included, the oldest first.
     *
     * @var list<array{invoiceId: string, number: string|null, status: string, total: string}>
     */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['invoiceId', 'number', 'status', 'total'],
            'properties' => [
                'invoiceId' => self::ID,
                'number' => self::TEXT_OR_NULL,
                'status' => ['type' => 'string', 'enum' => ['draft', 'issued', 'partially_paid', 'paid', 'cancelled']],
                'total' => ['type' => 'string', 'description' => 'Tax and fixed charges included, at the currency\'s scale.'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $deposits = [];

    /** Sent to draw a deposit: a percentage of the quote above 0 and below 100; or null, with an amount. */
    #[ApiProperty(readable: false, schema: ['type' => ['string', 'null'], 'example' => '30'])]
    #[Assert\Type('string', groups: [self::DEPOSIT])]
    #[Groups([self::DEPOSIT])]
    public ?string $depositPercentage = null;

    /** Sent to draw a deposit: an amount tax included below the quote's total; or null, with a percentage. */
    #[ApiProperty(readable: false, schema: ['type' => ['string', 'null'], 'example' => '500.000'])]
    #[Assert\Type('string', groups: [self::DEPOSIT])]
    #[Groups([self::DEPOSIT])]
    public ?string $depositAmount = null;

    /** How many files are attached, such as the signed copy (GET .../attachments). */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $attachmentCount = 0;

    /** The customer's own reference for the request. */
    #[Assert\Length(max: QuoteHeader::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $customerReference = null;

    /** Printed on the quote. */
    #[Assert\Length(max: QuoteHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notesPrinted = null;

    /** Kept inside the company, never printed. */
    #[Assert\Length(max: QuoteHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notesInternal = null;

    /** A discount on the whole quote, an amount at most what the lines come to, at the currency's scale. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '50.000'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $discountAmount = null;

    /**
     * The lines, in order. A line naming a product may leave out its description, unit, price and taxes (null), which
     * then come from the product; `net` is answered, never read.
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
                'description' => ['type' => ['string', 'null'], 'maxLength' => QuoteLineDetails::DESCRIPTION_MAX],
                'quantity' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '2.5'],
                'unitId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'unitPriceNet' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$', 'example' => '1250.5000'],
                'discountRate' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,2})(\.[0-9]{1,3})?$', 'example' => '10'],
                'taxComponentIds' => ['type' => ['array', 'null'], 'items' => self::ID],
                'net' => ['type' => 'string', 'readOnly' => true],
            ],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(fields: [
        'productId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'description' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Length(max: QuoteLineDetails::DESCRIPTION_MAX, groups: [self::WRITE])], groups: [self::WRITE]),
        'quantity' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'unitId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'unitPriceNet' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'discountRate' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'taxComponentIds' => new Assert\Optional([
            new Assert\Type('list', groups: [self::WRITE]),
            new Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        ], groups: [self::WRITE]),
    ], allowExtraFields: true, groups: [self::WRITE])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $lines = [];

    /** The lines' net amounts added up. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $subtotalNet = '0';

    /** The document discount as it applies, 0 without one. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $documentDiscount = '0';

    /** @var list<array{code: string, rate: string, base: string, amount: string}> each line tax over the lines carrying it */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'rate', 'base', 'amount'],
            'properties' => ['code' => ['type' => 'string'], 'rate' => ['type' => 'string'], 'base' => ['type' => 'string'], 'amount' => ['type' => 'string']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $taxes = [];

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $totalTax = '0';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $total = '0';

    /** @param \DateTimeImmutable $today the company's day, which says whether a sent quote has expired */
    /** @param list<QuoteDeposit> $deposits */
    public static function of(Quote $quote, DocumentTotals $totals, \DateTimeImmutable $today, int $attachmentCount, array $deposits = []): self
    {
        $header = $quote->getHeader();
        $resource = new self();
        $resource->id = $quote->getId()->toRfc4122();
        $resource->number = $quote->getNumber();
        $resource->status = $quote->getStatus()->value;
        $resource->expired = $quote->isExpired($today);
        $resource->customerId = $quote->getCustomer()->getId()->toRfc4122();
        $resource->customerName = $quote->getCustomer()->getProfile()->name;
        $resource->establishmentId = $quote->getEstablishment()->getId()->toRfc4122();
        $snapshot = $quote->getCustomerSnapshot();
        $resource->customerSnapshot = null === $snapshot ? null : ['identifiers' => new \ArrayObject($snapshot->identifiers)] + $snapshot->toArray();
        $resource->issueDate = $quote->getIssueDate()?->format('Y-m-d');
        $resource->validUntil = $quote->getValidUntil()?->format('Y-m-d');
        $resource->answeredOn = $quote->getAnsweredOn()?->format('Y-m-d');
        $resource->refusalReason = $quote->getRefusalReason();
        $resource->invoiceId = $quote->getInvoiceId()?->toRfc4122();
        $resource->attachmentCount = $attachmentCount;
        $resource->deposits = array_map(static fn (QuoteDeposit $deposit): array => ['invoiceId' => $deposit->invoiceId, 'number' => $deposit->number, 'status' => $deposit->status, 'total' => $deposit->total], $deposits);
        $resource->customerReference = $header->customerReference;
        $resource->notesPrinted = $header->notesPrinted;
        $resource->notesInternal = $header->notesInternal;
        $resource->discountAmount = $header->discountAmount;
        $resource->lines = array_map(static fn (QuoteLine $line, LineTotals $figures): array => [
            'productId' => $line->getProduct()?->getId()->toRfc4122(),
            'productReference' => $line->getProduct()?->getReference(),
            'productName' => $line->getProduct()?->getDetails()->name,
            'description' => $line->getDescription(),
            'quantity' => $line->getQuantity(),
            'unitId' => $line->getUnit()->getId()->toRfc4122(),
            'unitPriceNet' => $line->getUnitPriceNet(),
            'discountRate' => $line->getDiscountRate(),
            'taxComponentIds' => array_map(static fn (QuoteLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $line->getTaxes()),
            'net' => $figures->net,
        ], $quote->getLines(), $totals->lines);
        $resource->subtotalNet = $totals->subtotalNet;
        $resource->documentDiscount = $totals->documentDiscount;
        $resource->taxes = array_map(static fn (TaxTotal $tax): array => [
            'code' => $tax->code,
            'rate' => Decimal::format(Decimal::of($tax->rate->percentage()), 3),
            'base' => $tax->base,
            'amount' => $tax->amount,
        ], $totals->taxes);
        $resource->totalTax = $totals->totalTax;
        $resource->total = $totals->total;

        return $resource;
    }

    /** @throws InvalidQuote */
    public function input(): QuoteInput
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $taxIds = $line['taxComponentIds'] ?? null;
            $lines[] = new QuoteLineInput(
                self::uuid(self::text($line, 'productId')),
                self::text($line, 'description'),
                self::text($line, 'quantity') ?? '',
                self::uuid(self::text($line, 'unitId')),
                self::text($line, 'unitPriceNet'),
                self::text($line, 'discountRate'),
                \is_array($taxIds) ? array_values(array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : ''), $taxIds)) : null,
            );
        }

        return new QuoteInput(
            Uuid::fromString($this->customerId),
            self::uuid($this->establishmentId),
            new QuoteHeader($this->customerReference, $this->notesPrinted, $this->notesInternal, $this->discountAmount),
            $lines,
        );
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
