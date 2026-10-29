<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Shared\Infrastructure\ApiPlatform\Payload\RecordVersion;
use App\Shared\Infrastructure\Validation\ContactRule;
use App\Tenancy\Domain\ValueObject\CompanyProfile;
use App\Tenancy\Infrastructure\ApiPlatform\Output\CompanyIdentifierField;
use App\Tenancy\Infrastructure\ApiPlatform\Output\CompanyVatRegimeOption;
use App\Tenancy\Infrastructure\ApiPlatform\State\Processor\ReviseCompanyProfileProcessor;
use App\Tenancy\Infrastructure\ApiPlatform\State\Provider\CompanyProfileProvider;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a company's documents say about it (docs/SPEC.md § 4 company). Read with `company.read`, revised with
 * `company.settings`; the answer also carries what the company's fiscal preset asks for, the identifiers with their
 * shape and the VAT regimes, so a form is rendered from it. The shape is checked here, the preset's rules by the use case.
 */
#[ApiResource(
    shortName: 'CompanyProfile',
    operations: [
        new Get(
            uriTemplate: '/companies/{companyId}/profile',
            provider: CompanyProfileProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/profile',
            processor: ReviseCompanyProfileProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
        ),
    ],
)]
final class CompanyProfileResource
{
    public const string READ = 'company_profile:read';
    public const string WRITE = 'company_profile:write';
    public const string READ_PERMISSION = 'company.read';
    public const string WRITE_PERMISSION = 'company.settings';

    private const int TEXT_MAX_LENGTH = 2000;

    /** A PHP array without entries serializes as `[]`; the identifiers are a JSON object even when there are none. */
    private const array NORMALIZATION = [
        'groups' => [self::READ],
        AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        AbstractNormalizer::CALLBACKS => ['identifiers' => [self::class, 'identifiersAsObject']],
    ];

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public string $name = '';

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public string $countryCode = '';

    /** Whether the caller may revise the profile: what the page offers, the PUT enforces. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $writable = false;

    /** The id of the stored logo file, which changes when the logo does: the screen caches the picture by it. Absent without a logo. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public ?string $logoVersion = null;

    /** The version read; a save sends back the one the form was opened at, and one saved since answers 409. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([self::READ, RecordVersion::REVISE])]
    public ?int $version = null;

    #[Assert\Length(max: 200, groups: [self::WRITE], payload: ['reason' => 'invalid_legal_name'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $legalName = null;

    #[Assert\Length(max: 80, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => 80]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $legalForm = null;

    /** @var array<string, string> */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string']])]
    #[Assert\All([new Assert\Type('string', payload: ['reason' => 'invalid_value']), new Assert\Length(max: 64, payload: ['reason' => 'invalid_text', 'params' => ['max' => 64]])], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $identifiers = [];

    #[Assert\Length(max: 200, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => 200]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine1 = null;

    #[Assert\Length(max: 200, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => 200]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine2 = null;

    #[Assert\Length(max: 20, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => 20]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $postalCode = null;

    #[Assert\Length(max: 120, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => 120]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $city = null;

    #[ContactRule(ContactRule::EMAIL, groups: [self::WRITE], onceChanged: true)]
    #[Assert\Length(max: 254, groups: [self::WRITE], payload: ['reason' => 'invalid_email'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $email = null;

    #[ContactRule(ContactRule::PHONE, groups: [self::WRITE], onceChanged: true)]
    #[Groups([self::READ, self::WRITE])]
    public ?string $phone = null;

    #[Assert\Url(requireTld: true, groups: [self::WRITE], payload: ['reason' => 'invalid_website'])]
    #[Assert\Length(max: 255, groups: [self::WRITE], payload: ['reason' => 'invalid_website'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $website = null;

    #[ContactRule(ContactRule::IBAN, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $iban = null;

    #[Assert\Bic(mode: Assert\Bic::VALIDATION_MODE_CASE_INSENSITIVE, groups: [self::WRITE], payload: ['reason' => 'invalid_bic'])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $bic = null;

    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Length(max: 32, groups: [self::WRITE], payload: ['reason' => 'unknown_tax_regime'])]
    #[Groups([self::READ, self::WRITE])]
    public string $vatRegime = CompanyProfile::STANDARD_REGIME;

    #[Assert\Length(max: self::TEXT_MAX_LENGTH, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => self::TEXT_MAX_LENGTH]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $invoiceFooterText = null;

    #[Assert\Length(max: self::TEXT_MAX_LENGTH, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => self::TEXT_MAX_LENGTH]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $latePenaltyText = null;

    /** Whether the company opted to pay VAT on the débits (docs/fiscal/FR.md § 4a); only where offersVatOnDebits says so. */
    #[Groups([self::READ, self::WRITE])]
    public bool $vatOnDebits = false;

    /** Whether the company's fiscal preset offers the débits option, so a form shows it. */
    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public bool $offersVatOnDebits = false;

    /** @var list<CompanyIdentifierField> */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'label', 'pattern', 'required', 'hint'],
            'properties' => [
                'key' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'pattern' => ['type' => ['string', 'null'], 'description' => 'A regular expression the whole value matches, without delimiters; null when the API alone judges every way of writing it.'],
                'required' => ['type' => 'boolean'],
                'hint' => ['type' => ['string', 'null'], 'description' => 'What the form shows under the field, its example in it, in the reader\'s language.'],
            ],
        ],
    ])]
    #[Groups([self::READ])]
    public array $identifierFields = [];

    /** @var list<CompanyVatRegimeOption> */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['code', 'label'],
            'properties' => ['code' => ['type' => 'string'], 'label' => ['type' => 'string']],
        ],
    ])]
    #[Groups([self::READ])]
    public array $vatRegimes = [];

    /**
     * @param array<string, string> $identifiers
     *
     * @return \ArrayObject<string, string>
     */
    public static function identifiersAsObject(array $identifiers): \ArrayObject
    {
        return new \ArrayObject($identifiers);
    }

    public function toProfile(): CompanyProfile
    {
        return new CompanyProfile(
            $this->legalName,
            $this->legalForm,
            $this->identifiers,
            $this->addressLine1,
            $this->addressLine2,
            $this->postalCode,
            $this->city,
            $this->email,
            $this->phone,
            $this->website,
            $this->iban,
            $this->bic,
            $this->vatRegime,
            $this->invoiceFooterText,
            $this->latePenaltyText,
            $this->vatOnDebits,
        );
    }
}
