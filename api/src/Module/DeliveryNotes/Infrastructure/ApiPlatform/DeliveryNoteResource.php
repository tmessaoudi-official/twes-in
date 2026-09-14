<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\LineTotals;
use App\Fiscal\Domain\Calculation\TaxTotal;
use App\Module\DeliveryNotes\Application\DeliveryNoteInput;
use App\Module\DeliveryNotes\Application\DeliveryNoteLineInput;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteHeader;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineDetails;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineTax;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Shared\Domain\PostalAddress;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's delivery notes (docs/SPEC.md § 4 delivery_note). Read with delivery_note.read, drafted and revised with
 * delivery_note.write; a note that is no longer a draft answers 409 to a revision. The shape is checked here, the
 * company's customers, products, units and taxes by the use case. The figures are worked out on every read and never
 * written: amounts are decimal strings at the currency's scale, quantities with three decimals, prices with four.
 */
#[ApiResource(
    shortName: 'DeliveryNote',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/delivery-notes',
            provider: DeliveryNoteCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/delivery-notes/{deliveryNoteId}',
            provider: DeliveryNoteItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/delivery-notes',
            processor: CreateDeliveryNoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/delivery-notes/{deliveryNoteId}',
            processor: ReviseDeliveryNoteProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class DeliveryNoteResource
{
    public const string READ = 'delivery_note:read';
    public const string WRITE = 'delivery_note:write';
    /** Nulls are answered: a draft's absent number and a line without a product read alike. */
    private const array NORMALIZATION = ['groups' => [self::READ], AbstractObjectNormalizer::SKIP_NULL_VALUES => false];
    private const array ID = ['type' => 'string', 'format' => 'uuid'];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The number validation gives the note; null while it is a draft. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $number = null;

    #[ApiProperty(writable: false, schema: ['type' => 'string', 'enum' => ['draft', 'validated', 'delivered', 'cancelled']])]
    #[Groups([self::READ])]
    public string $status = 'draft';

    /** One of the company's active customers (GET .../delivery-note-options). */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $customerId = '';

    /** One of the company's establishments; left out, the default one. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $establishmentId = null;

    /** The day validation numbered the note, in the company's time zone; null while it is a draft. */
    #[ApiProperty(writable: false, schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Groups([self::READ])]
    public ?string $issueDate = null;

    /** The day the goods are expected, YYYY-MM-DD. */
    #[ApiProperty(schema: ['type' => ['string', 'null'], 'format' => 'date'])]
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $deliveryDate = null;

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $deliveryAddressLine1 = null;

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $deliveryAddressLine2 = null;

    #[Assert\Length(max: 20, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $deliveryPostalCode = null;

    #[Assert\Length(max: 120, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $deliveryCity = null;

    #[Assert\Country(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $deliveryCountryCode = null;

    /** The customer's own reference for the order, such as a purchase order number. */
    #[Assert\Length(max: DeliveryNoteHeader::REFERENCE_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $customerReference = null;

    /** Printed on the note. */
    #[Assert\Length(max: DeliveryNoteHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $remarksPrinted = null;

    /** Kept inside the company, never printed. */
    #[Assert\Length(max: DeliveryNoteHeader::TEXT_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notesInternal = null;

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
                'description' => ['type' => ['string', 'null'], 'maxLength' => DeliveryNoteLineDetails::DESCRIPTION_MAX],
                'quantity' => ['type' => 'string', 'pattern' => '^(0|[1-9][0-9]{0,10})(\.[0-9]{1,3})?$', 'example' => '2.5'],
                'unitId' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'unitPriceNet' => ['type' => ['string', 'null'], 'pattern' => '^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$', 'example' => '1250.5000'],
                'taxComponentIds' => ['type' => ['array', 'null'], 'items' => self::ID],
                'net' => ['type' => 'string', 'readOnly' => true],
            ],
        ],
    ])]
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Collection(fields: [
        'productId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'description' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Length(max: DeliveryNoteLineDetails::DESCRIPTION_MAX, groups: [self::WRITE])], groups: [self::WRITE]),
        'quantity' => new Assert\Required([new Assert\NotBlank(groups: [self::WRITE]), new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
        'unitId' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE]), new Assert\Uuid(groups: [self::WRITE])], groups: [self::WRITE]),
        'unitPriceNet' => new Assert\Optional([new Assert\Type('string', groups: [self::WRITE])], groups: [self::WRITE]),
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

    public static function of(DeliveryNote $note, DocumentTotals $totals): self
    {
        $header = $note->getHeader();
        $resource = new self();
        $resource->id = $note->getId()->toRfc4122();
        $resource->number = $note->getNumber();
        $resource->status = $note->getStatus()->value;
        $resource->customerId = $note->getCustomer()->getId()->toRfc4122();
        $resource->establishmentId = $note->getEstablishment()->getId()->toRfc4122();
        $resource->issueDate = $note->getIssueDate()?->format('Y-m-d');
        $resource->deliveryDate = $header->deliveryDate?->format('Y-m-d');
        [$resource->deliveryAddressLine1, $resource->deliveryAddressLine2, $resource->deliveryPostalCode, $resource->deliveryCity, $resource->deliveryCountryCode] = $header->deliveryAddress->parts();
        $resource->customerReference = $header->customerReference;
        $resource->remarksPrinted = $header->remarksPrinted;
        $resource->notesInternal = $header->notesInternal;
        $resource->lines = array_map(static fn (DeliveryNoteLine $line, LineTotals $figures): array => [
            'productId' => $line->getProduct()?->getId()->toRfc4122(),
            'description' => $line->getDescription(),
            'quantity' => $line->getQuantity(),
            'unitId' => $line->getUnit()->getId()->toRfc4122(),
            'unitPriceNet' => $line->getUnitPriceNet(),
            'taxComponentIds' => array_map(static fn (DeliveryNoteLineTax $tax): string => $tax->getTaxComponent()->getId()->toRfc4122(), $line->getTaxes()),
            'net' => $figures->net,
        ], $note->getLines(), $totals->lines);
        $resource->subtotalNet = $totals->subtotalNet;
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

    /** @throws InvalidDeliveryNote */
    public function input(): DeliveryNoteInput
    {
        $lines = [];
        foreach ($this->lines as $line) {
            $taxIds = $line['taxComponentIds'] ?? null;
            $lines[] = new DeliveryNoteLineInput(
                self::uuid(self::text($line, 'productId')),
                self::text($line, 'description'),
                self::text($line, 'quantity') ?? '',
                self::uuid(self::text($line, 'unitId')),
                self::text($line, 'unitPriceNet'),
                \is_array($taxIds) ? array_values(array_map(static fn (mixed $id): Uuid => Uuid::fromString(\is_string($id) ? $id : ''), $taxIds)) : null,
            );
        }

        return new DeliveryNoteInput(
            Uuid::fromString($this->customerId),
            self::uuid($this->establishmentId),
            new DeliveryNoteHeader(
                null === $this->deliveryDate ? null : new \DateTimeImmutable($this->deliveryDate, new \DateTimeZone('UTC')),
                new PostalAddress($this->deliveryAddressLine1, $this->deliveryAddressLine2, $this->deliveryPostalCode, $this->deliveryCity, $this->deliveryCountryCode),
                $this->customerReference,
                $this->remarksPrinted,
                $this->notesInternal,
            ),
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
