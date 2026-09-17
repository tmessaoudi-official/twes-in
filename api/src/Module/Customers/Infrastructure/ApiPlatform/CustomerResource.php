<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Domain\Customer;
use App\Module\Customers\Domain\CustomerKind;
use App\Module\Customers\Domain\CustomerProfile;
use App\Module\Customers\Domain\InvalidCustomer;
use App\Shared\Domain\PostalAddress;
use App\Tenancy\Domain\Company;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's customers (docs/SPEC.md § 4 customer). Read with customer.read, added and revised with customer.write;
 * never deleted, deactivated instead. The shape is checked here, the company's preset and the rest by the use case.
 * An address left without a country is in the company's own; a shipping address left empty is the billing address.
 */
#[ApiResource(
    shortName: 'Customer',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/customers',
            outputFormats: ['jsonld' => ['application/ld+json']],
            provider: CustomerCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            parameters: [
                'q' => new QueryParameter(schema: ['type' => 'string', 'maxLength' => 100], description: 'Words found in the number, name, legal name, email, billing address or registration numbers, whatever their case and accents; under three characters, the exact number only.'),
                'kind' => new QueryParameter(schema: ['type' => 'string', 'enum' => ['company', 'individual']]),
                'customerGroupId' => new QueryParameter(schema: ['type' => 'string', 'format' => 'uuid'], constraints: [new Assert\Uuid()]),
                'isActive' => new QueryParameter(schema: ['type' => 'boolean'], castToNativeType: true),
                'order[number]' => new QueryParameter(schema: self::DIRECTION),
                'order[name]' => new QueryParameter(schema: self::DIRECTION),
                'order[kind]' => new QueryParameter(schema: self::DIRECTION),
                'order[customerGroup]' => new QueryParameter(schema: self::DIRECTION, description: 'By the name of the group.'),
                'order[city]' => new QueryParameter(schema: self::DIRECTION, description: 'By the billing city.'),
                'order[isActive]' => new QueryParameter(schema: self::DIRECTION),
            ],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/customers/{customerId}',
            provider: CustomerItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/customers',
            processor: CreateCustomerProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/customers/{customerId}',
            processor: ReviseCustomerProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: self::NORMALIZATION,
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
    ],
)]
final class CustomerResource
{
    public const string READ = 'customer:read';
    public const string WRITE = 'customer:write';

    /** A PHP array without entries serializes as `[]`; the identifiers are a JSON object even when there are none. */
    private const array NORMALIZATION = [
        'groups' => [self::READ],
        AbstractObjectNormalizer::PRESERVE_EMPTY_OBJECTS => true,
        AbstractNormalizer::CALLBACKS => ['identifiers' => [self::class, 'asObject'], 'customFields' => [self::class, 'asObject']],
    ];
    private const array DIRECTION = ['type' => 'string', 'enum' => ['asc', 'desc']];
    private const string PHONE = '/^\+?[0-9 ().\-]{3,40}$/';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Regex(pattern: Customer::NUMBER, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $number = '';

    #[ApiProperty(schema: ['type' => 'string', 'enum' => ['company', 'individual']])]
    #[Assert\Choice(choices: ['company', 'individual'], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $kind = 'company';

    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $customerGroupId = null;

    /** The code of one of the regimes the company's preset offers customers. */
    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: 24, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $taxRegime = 'standard';

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: CustomerProfile::NAME_MAX, groups: [self::WRITE])]
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
    public ?string $billingAddressLine1 = null;

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingAddressLine2 = null;

    #[Assert\Length(max: 20, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingPostalCode = null;

    #[Assert\Length(max: 120, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingCity = null;

    #[Assert\Country(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingCountryCode = null;

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingAddressLine1 = null;

    #[Assert\Length(max: 200, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingAddressLine2 = null;

    #[Assert\Length(max: 20, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingPostalCode = null;

    #[Assert\Length(max: 120, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingCity = null;

    #[Assert\Country(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingCountryCode = null;

    /** @var list<string> the ids of the company's taxes a new line for this customer starts with */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Uuid()], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public array $defaultTaxComponentIds = [];

    /** A percentage from 0 to 100, as a decimal string. */
    #[Assert\Regex(pattern: '/^[0-9]{1,3}(\.[0-9]{1,3})?$/', groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $defaultDiscountRate = null;

    #[Assert\Length(max: 5000, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notes = null;

    /** @var array<string, string|int|float|bool> values by the company's custom field keys (GET .../custom-fields?entity=customer) */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['oneOf' => [['type' => 'string'], ['type' => 'number'], ['type' => 'boolean']]]])]
    #[Groups([self::READ, self::WRITE])]
    public array $customFields = [];

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    public static function of(Customer $customer): self
    {
        $profile = $customer->getProfile();
        $resource = new self();
        $resource->id = $customer->getId()->toRfc4122();
        $resource->number = $customer->getNumber();
        $resource->kind = $profile->kind->value;
        $resource->customerGroupId = $customer->getGroup()?->getId()->toRfc4122();
        $resource->taxRegime = $customer->getTaxRegime()->getCode();
        $resource->name = $profile->name;
        $resource->legalName = $profile->legalName;
        $resource->identifiers = $profile->identifiers;
        $resource->email = $profile->email;
        $resource->phone = $profile->phone;
        $resource->website = $profile->website;
        [$resource->billingAddressLine1, $resource->billingAddressLine2, $resource->billingPostalCode, $resource->billingCity, $resource->billingCountryCode] = $profile->billingAddress->parts();
        [$resource->shippingAddressLine1, $resource->shippingAddressLine2, $resource->shippingPostalCode, $resource->shippingCity, $resource->shippingCountryCode] = $profile->shippingAddress?->parts() ?? [null, null, null, null, null];
        $resource->defaultTaxComponentIds = $customer->getDefaultTaxComponentIds();
        $resource->defaultDiscountRate = $profile->defaultDiscountRate;
        $resource->notes = $profile->notes;
        $resource->customFields = $customer->getCustomFields();
        $resource->isActive = $customer->isActive();

        return $resource;
    }

    /** @throws InvalidCustomer */
    public function input(Company $company): CustomerInput
    {
        $billing = new PostalAddress($this->billingAddressLine1, $this->billingAddressLine2, $this->billingPostalCode, $this->billingCity, $this->billingCountryCode);
        $shipping = new PostalAddress($this->shippingAddressLine1, $this->shippingAddressLine2, $this->shippingPostalCode, $this->shippingCity, $this->shippingCountryCode);

        return new CustomerInput(
            $this->number,
            new CustomerProfile(
                CustomerKind::from($this->kind),
                $this->name,
                $this->legalName,
                $this->identifiers,
                $this->email,
                $this->phone,
                $this->website,
                self::inCompanyCountry($billing, $company),
                $shipping->isEmpty() ? null : self::inCompanyCountry($shipping, $company),
                $this->defaultDiscountRate,
                $this->notes,
            ),
            null === $this->customerGroupId ? null : Uuid::fromString($this->customerGroupId),
            $this->taxRegime,
            array_map(static fn (string $id): Uuid => Uuid::fromString($id), $this->defaultTaxComponentIds),
            $this->isActive,
            $this->customFields,
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

    private static function inCompanyCountry(PostalAddress $address, Company $company): PostalAddress
    {
        return null !== $address->countryCode ? $address : new PostalAddress($address->line1, $address->line2, $address->postalCode, $address->city, $company->getCountryCode());
    }
}
