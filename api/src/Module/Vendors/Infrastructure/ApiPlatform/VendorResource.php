<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Vendors\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Vendors\Application\VendorInput;
use App\Module\Vendors\Domain\InvalidVendor;
use App\Module\Vendors\Domain\Vendor;
use App\Module\Vendors\Domain\VendorProfile;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
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
            provider: VendorCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
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
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class VendorResource
{
    public const string READ = 'vendor:read';
    public const string WRITE = 'vendor:write';

    /** A PHP array without entries serializes as `[]`; the identifiers are a JSON object even when there are none. */
    private const array NORMALIZATION = [
        'groups' => [self::READ],
        AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        AbstractNormalizer::CALLBACKS => ['identifiers' => [self::class, 'asObject']],
    ];
    private const string PHONE = '/^\+?[0-9 ().\-]{3,40}$/';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Regex(pattern: Vendor::NUMBER, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $number = '';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: VendorProfile::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $legalName = null;

    /** @var array<string, string> */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['type' => 'string']])]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 64)], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $identifiers = [];

    #[Assert\Email(groups: [self::WRITE])]
    #[Assert\Length(max: 254, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $email = null;

    #[Assert\Regex(pattern: self::PHONE, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $phone = null;

    #[Assert\Url(requireTld: true, groups: [self::WRITE])]
    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $website = null;

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

    #[Assert\Country(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $countryCode = null;

    #[Assert\Iban(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $iban = null;

    #[Assert\Bic(mode: Assert\Bic::VALIDATION_MODE_CASE_INSENSITIVE, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $bic = null;

    /** Days after the vendor's invoice its payment is due. */
    #[Assert\Range(min: 0, max: VendorProfile::PAYMENT_TERMS_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?int $paymentTermsDays = null;

    #[Assert\Length(max: 5000, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notes = null;

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    public static function of(Vendor $vendor): self
    {
        $profile = $vendor->getProfile();
        $resource = new self();
        $resource->id = $vendor->getId()->toRfc4122();
        $resource->number = $vendor->getNumber();
        $resource->name = $profile->name;
        $resource->legalName = $profile->legalName;
        $resource->identifiers = $profile->identifiers;
        $resource->email = $profile->email;
        $resource->phone = $profile->phone;
        $resource->website = $profile->website;
        [$resource->addressLine1, $resource->addressLine2, $resource->postalCode, $resource->city, $resource->countryCode] = $profile->address->parts();
        $resource->iban = $profile->iban;
        $resource->bic = $profile->bic;
        $resource->paymentTermsDays = $profile->paymentTermsDays;
        $resource->notes = $profile->notes;
        $resource->isActive = $vendor->isActive();

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
            ),
            $this->isActive,
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
