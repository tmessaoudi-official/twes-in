<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\Tenancy\Domain\CompanyProfile;
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
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
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

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $legalName = null;

    #[Assert\Length(max: 80, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $legalForm = null;

    /** @var array<string, string> */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string']])]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 64)], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $identifiers = [];

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine1 = null;

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $addressLine2 = null;

    #[Assert\Length(max: 20, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $postalCode = null;

    #[Assert\Length(max: 120, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $city = null;

    #[Assert\Email(groups: [self::WRITE])]
    #[Assert\Length(max: 254, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $email = null;

    #[Assert\Regex(pattern: '/^\+?[0-9 ().\-]{3,40}$/', groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $phone = null;

    #[Assert\Url(requireTld: true, groups: [self::WRITE])]
    #[Assert\Length(max: 255, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $website = null;

    #[Assert\Iban(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $iban = null;

    #[Assert\Bic(mode: Assert\Bic::VALIDATION_MODE_CASE_INSENSITIVE, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $bic = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: 32, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $vatRegime = CompanyProfile::STANDARD_REGIME;

    #[Assert\Length(max: self::TEXT_MAX_LENGTH, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $invoiceFooterText = null;

    #[Assert\Length(max: self::TEXT_MAX_LENGTH, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $latePenaltyText = null;

    /** @var list<CompanyIdentifierField> */
    #[ApiProperty(writable: false, schema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['key', 'label', 'pattern', 'required'],
            'properties' => [
                'key' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'pattern' => ['type' => 'string', 'description' => 'A regular expression the whole value matches, without delimiters.'],
                'required' => ['type' => 'boolean'],
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
        );
    }
}
