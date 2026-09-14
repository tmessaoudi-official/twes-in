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
use App\Fiscal\Domain\Calculation\ChargeTotal;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\LineTotals;
use App\Fiscal\Domain\Calculation\TaxTotal;
use App\Module\Invoices\Application\InvoiceInput;
use App\Module\Invoices\Application\InvoiceLineInput;
use App\Module\Invoices\Domain\InvalidInvoice;
use App\Module\Invoices\Domain\Invoice;
use App\Module\Invoices\Domain\InvoiceHeader;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Module\Invoices\Domain\InvoiceLineDetails;
use App\Module\Invoices\Domain\InvoiceLineTax;
use App\Module\Invoices\Domain\InvoiceTax;
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
            provider: InvoiceCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
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
            uriTemplate: '/companies/{companyId}/invoices/{invoiceId}/cancel',
            status: 200,
            processor: CancelInvoiceProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            input: false,
            normalizationContext: self::NORMALIZATION,
        ),
    ],
)]
final class InvoiceResource
{
    public const string READ = 'invoice:read';
    public const string WRITE = 'invoice:write';
    /** Nulls are answered: a draft's absent number and a line without a product read alike. */
    private const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false, AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true];
    private const array ID = ['type' => 'string', 'format' => 'uuid'];
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

    /** The number issuing gives the document; null while it is a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $number = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['draft', 'issued', 'partially_paid', 'paid', 'cancelled']])]
    #[Groups([self::READ])]
    public string $status = 'draft';

    /** One of the company's active customers (GET .../invoice-options). */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $customerId = '';

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
     * then come from the product; `net` is answered, never read: the line after its own discount.
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
                'description' => ['type' => ['string', 'null'], 'maxLength' => InvoiceLineDetails::DESCRIPTION_MAX],
                'quantity' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '2.5'],
                'unitId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'unitPriceNet' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$', 'example' => '1250.5000'],
                'discountRate' => ['type' => ['string', 'null'], 'example' => '10'],
                'taxComponentIds' => ['type' => ['array', 'null'], 'items' => self::ID],
                'net' => ['type' => 'string', 'readOnly' => true],
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
        'taxComponentIds' => new Assert\Optional([
            new Assert\Type('list', groups: [self::WRITE]),
            new Assert\All([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        ], groups: [self::WRITE]),
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

    /** The total less what is withheld: what the customer pays. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $amountDue = '0';

    public static function of(Invoice $invoice, DocumentTotals $totals): self
    {
        $header = $invoice->getHeader();
        $resource = new self();
        $resource->id = $invoice->getId()->toRfc4122();
        $resource->type = $invoice->getType()->value;
        $resource->correctsInvoiceId = $invoice->getCorrectedInvoice()?->getId()->toRfc4122();
        $resource->number = $invoice->getNumber();
        $resource->status = $invoice->getStatus()->value;
        $resource->customerId = $invoice->getCustomer()->getId()->toRfc4122();
        $resource->establishmentId = $invoice->getEstablishment()->getId()->toRfc4122();
        $resource->issueDate = $invoice->getIssueDate()?->format('Y-m-d');
        $resource->supplyDate = $header->supplyDate?->format('Y-m-d');
        $resource->paymentTermsDays = $header->paymentTermsDays;
        $resource->customerReference = $header->customerReference;
        $resource->notesPrinted = $header->notesPrinted;
        $resource->notesInternal = $header->notesInternal;
        $resource->discountAmount = $header->discountAmount;
        $resource->documentTaxComponentIds = array_map(static fn (InvoiceTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $invoice->getDocumentTaxes());
        $resource->lines = array_map(static fn (InvoiceLine $line, LineTotals $figures): array => [
            'productId' => $line->getProduct()?->getId()->toRfc4122(),
            'description' => $line->getDescription(),
            'quantity' => $line->getQuantity(),
            'unitId' => $line->getUnit()->getId()->toRfc4122(),
            'unitPriceNet' => $line->getUnitPriceNet(),
            'discountRate' => $line->getDiscountRate(),
            'taxComponentIds' => array_map(static fn (InvoiceLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $line->getTaxes()),
            'net' => $figures->net,
        ], $invoice->getLines(), $totals->lines);
        $resource->subtotalNet = $totals->subtotalNet;
        $resource->documentDiscount = $totals->documentDiscount;
        $resource->totalNet = $totals->netAfterDocumentDiscount;
        $resource->taxes = array_map(self::taxTotal(...), $totals->taxes);
        $resource->totalTax = $totals->totalTax;
        $resource->fixedTaxes = array_map(static fn (ChargeTotal $charge): array => ['code' => $charge->code, 'amount' => $charge->amount], $totals->fixedCharges);
        $resource->total = $totals->total;
        $resource->withholdings = array_map(self::taxTotal(...), $totals->withholdings);
        $resource->amountDue = $totals->amountDue;

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
            ),
            $lines,
            null === $this->documentTaxComponentIds ? null : self::uuids($this->documentTaxComponentIds),
        );
    }

    /** @return array{code: string, rate: string, base: string, amount: string} */
    private static function taxTotal(TaxTotal $tax): array
    {
        return ['code' => $tax->code, 'rate' => Decimal::format(Decimal::of($tax->rate->percentage()), 3), 'base' => $tax->base, 'amount' => $tax->amount];
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
