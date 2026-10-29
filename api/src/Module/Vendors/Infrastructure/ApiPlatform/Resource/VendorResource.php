<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Domain\Exception\InvalidVendor;
use App\Module\Vendors\Domain\Model\Vendor;
use App\Module\Vendors\Domain\ValueObject\VendorCheckReason;
use App\Module\Vendors\Domain\ValueObject\VendorProfile;
use App\Module\Vendors\Infrastructure\ApiPlatform\State\Processor\CreateVendorProcessor;
use App\Module\Vendors\Infrastructure\ApiPlatform\State\Processor\ReviseVendorProcessor;
use App\Module\Vendors\Infrastructure\ApiPlatform\State\Provider\VendorCollectionProvider;
use App\Module\Vendors\Infrastructure\ApiPlatform\State\Provider\VendorItemProvider;
use App\Shared\Domain\Model\PostalAddress;
use App\Shared\Domain\ValueObject\CheckMark;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use App\Shared\Infrastructure\Intl\GroupedPhones;
use App\Shared\Infrastructure\Validation\ContactRule;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's vendors (docs/SPEC.md § 4 vendor). Read with vendor.read, added and revised with vendor.write; never
 * deleted, deactivated instead. The shape is checked here, the company's preset by the use case. An address left
 * without a country is in the company's own.
 */
#[ApiResource(
    shortName: 'Vendor',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/vendors',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: VendorCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the number, name, legal name, email, address or registration numbers, whatever their case and accents; under three characters, the exact number only.'),
                'isActive' => new QueryParameter(schema: ['type' => 'boolean']),
                'toCheck' => new QueryParameter(schema: ['type' => 'boolean'], description: '« À vérifier »: marked by a person or with a name in doubt; false, neither.'),
                'paymentTermsDays[min]' => new QueryParameter(schema: ['type' => 'string', 'pattern' => '^[0-9]{1,4}$'], description: 'Payment terms of at least this many days; a vendor with none is left out.'),
                'paymentTermsDays[max]' => new QueryParameter(schema: ['type' => 'string', 'pattern' => '^[0-9]{1,4}$'], description: 'Payment terms of at most this many days; a vendor with none is left out.'),
                'createdAt[from]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Created on or after this day, in the company\'s own calendar.'),
                'createdAt[to]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Created on or before this day, in the company\'s own calendar.'),
                'order[number]' => new QueryParameter(schema: self::DIRECTION),
                'order[name]' => new QueryParameter(schema: self::DIRECTION),
                'order[city]' => new QueryParameter(schema: self::DIRECTION),
                'order[paymentTermsDays]' => new QueryParameter(schema: self::DIRECTION),
                'order[isActive]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/vendors/{vendorId}',
            provider: VendorItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/vendors',
            processor: CreateVendorProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/vendors/{vendorId}',
            processor: ReviseVendorProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
        ),
    ],
)]
final class VendorResource
{
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];
    public const string READ = 'vendor:read';
    public const string WRITE = 'vendor:write';

    /** A PHP array without entries serializes as `[]`; the identifiers are a JSON object even when there are none. */
    private const array NORMALIZATION = [
        'groups' => [self::READ],
        AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        AbstractNormalizer::CALLBACKS => ['identifiers' => [self::class, 'asObject']],
    ];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The version read; a revision sends back the one it was opened at, and one saved since answers 409. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([self::READ, RecordVersion::REVISE])]
    public ?int $version = null;

    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Regex(pattern: Vendor::NUMBER, groups: [self::WRITE], payload: ['reason' => 'invalid_number'])]
    #[Groups([self::READ, self::WRITE])]
    public string $number = '';

    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Length(max: VendorProfile::NAME_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_name'])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[Assert\Length(max: VendorProfile::LEGAL_NAME_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_legal_name'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $legalName = null;

    /** @var array<string, string> */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string']])]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 64)], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $identifiers = [];

    #[ContactRule(ContactRule::EMAIL, groups: [self::WRITE], onceChanged: true)]
    #[Groups([self::READ, self::WRITE])]
    public ?string $email = null;

    #[ContactRule(ContactRule::PHONE, groups: [self::WRITE], onceChanged: true)]
    #[Groups([self::READ, self::WRITE])]
    public ?string $phone = null;

    #[ContactRule(ContactRule::WEBSITE, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $website = null;

    #[Assert\Length(max: PostalAddress::LINE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::LINE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine1 = null;

    #[Assert\Length(max: PostalAddress::LINE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::LINE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine2 = null;

    #[Assert\Length(max: PostalAddress::POSTAL_CODE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::POSTAL_CODE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $postalCode = null;

    #[Assert\Length(max: PostalAddress::CITY_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::CITY_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $city = null;

    #[ContactRule(ContactRule::COUNTRY, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $countryCode = null;

    // A bare RIB is the IBAN it spells, held then to the domain's shape and checksum and to its country's length.
    #[ContactRule(ContactRule::IBAN, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $iban = null;

    #[ContactRule(ContactRule::BIC, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $bic = null;

    /** Days after the vendor's invoice its payment is due. */
    #[Assert\Range(min: 0, max: VendorProfile::PAYMENT_TERMS_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_payment_terms', 'params' => ['max' => VendorProfile::PAYMENT_TERMS_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?int $paymentTermsDays = null;

    #[Assert\Length(max: VendorProfile::NOTES_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => VendorProfile::NOTES_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notes = null;

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    /** « À vérifier » as a person set it, on the form or through a file's row. */
    #[Groups([self::READ, self::WRITE])]
    public bool $toCheck = false;

    /** What to look at, kept while the mark is on; dropped when it is taken off. */
    #[Assert\Length(max: CheckMark::NOTE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => CheckMark::NOTE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $checkNote = null;

    /** « Nom douteux », set by a file; a rename clears it, and so does a person sending false. True never sets it. */
    #[Groups([self::READ, self::WRITE])]
    public bool $nameDoubtful = false;

    /**
     * What the vendor lacks, each clearing itself once fixed.
     *
     * @var list<string>
     */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['name_doubtful']]])]
    #[Groups([self::READ])]
    public array $checkReasons = [];

    /** The expense category this vendor's expenses usually go to. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $defaultExpenseCategoryId = null;

    /**
     * The vendor kept when this one was merged away: lists, reports and pickers show that one wherever this one is named.
     *
     * @var array{id: string, number: string, name: string}|null
     */
    #[ApiProperty(schema: ['anyOf' => [['type' => 'object', 'required' => ['id', 'number', 'name'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'number' => ['type' => 'string'], 'name' => ['type' => 'string']]], ['type' => 'null']]])]
    #[Groups([self::READ])]
    public ?array $mergedInto = null;

    public static function of(Vendor $vendor): self
    {
        $profile = $vendor->getProfile();
        $resource = new self();
        $resource->id = $vendor->getId()->toRfc4122();
        $resource->version = $vendor->getVersion();
        $resource->number = $vendor->getNumber();
        $resource->name = $profile->name;
        $resource->legalName = $profile->legalName;
        $resource->identifiers = $profile->identifiers;
        $resource->email = $profile->email;
        $resource->phone = GroupedPhones::of($profile->phone);
        $resource->website = $profile->website;
        [$resource->addressLine1, $resource->addressLine2, $resource->postalCode, $resource->city, $resource->countryCode] = $profile->address->parts();
        $resource->iban = $profile->iban;
        $resource->bic = $profile->bic;
        $resource->paymentTermsDays = $profile->paymentTermsDays;
        $resource->notes = $profile->notes;
        $resource->isActive = $vendor->isActive();
        $mark = $vendor->getCheckMark();
        $resource->toCheck = $mark->marked;
        $resource->checkNote = $mark->note;
        $resource->nameDoubtful = $vendor->isNameDoubtful();
        $resource->checkReasons = array_map(static fn (VendorCheckReason $reason): string => $reason->value, $vendor->checkReasons());
        $resource->defaultExpenseCategoryId = $profile->defaultExpenseCategoryId?->toRfc4122();
        $kept = $vendor->getMergedInto();
        $resource->mergedInto = null === $kept ? null : ['id' => $kept->getId()->toRfc4122(), 'number' => $kept->getNumber(), 'name' => $kept->getProfile()->name];

        return $resource;
    }

    /** @throws InvalidVendor */
    public function input(Company $company): VendorInput
    {
        $address = new PostalAddress($this->addressLine1, $this->addressLine2, $this->postalCode, $this->city, $this->countryCode);
        if (null === $address->countryCode) {
            $address = new PostalAddress($address->line1, $address->line2, $address->postalCode, $address->city, $company->getCountryCode());
        }

        return new VendorInput(
            $this->number,
            new VendorProfile(
                $this->name,
                $this->legalName,
                $this->identifiers,
                $this->email,
                $this->phone,
                $this->website,
                $address,
                $this->iban,
                $this->bic,
                $this->paymentTermsDays,
                $this->notes,
                null === $this->defaultExpenseCategoryId ? null : Uuid::fromString($this->defaultExpenseCategoryId),
            ),
            $this->isActive,
            new CheckMark($this->toCheck, $this->checkNote),
            $this->nameDoubtful,
        );
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return \ArrayObject<string, mixed>
     */
    public static function asObject(array $values): \ArrayObject
    {
        return new \ArrayObject($values);
    }
}
