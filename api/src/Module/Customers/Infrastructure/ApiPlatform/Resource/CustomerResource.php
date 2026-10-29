<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform\Resource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\QueryParameter;
use App\Module\Customers\Application\CustomerInput;
use App\Module\Customers\Domain\Exception\InvalidCustomer;
use App\Module\Customers\Domain\Model\Customer;
use App\Module\Customers\Domain\ValueObject\CustomerCheckReason;
use App\Module\Customers\Domain\ValueObject\CustomerCheckScope;
use App\Module\Customers\Domain\ValueObject\CustomerKind;
use App\Module\Customers\Domain\ValueObject\CustomerProfile;
use App\Module\Customers\Infrastructure\ApiPlatform\State\Processor\CreateCustomerProcessor;
use App\Module\Customers\Infrastructure\ApiPlatform\State\Processor\ReviseCustomerProcessor;
use App\Module\Customers\Infrastructure\ApiPlatform\State\Provider\CustomerCollectionProvider;
use App\Module\Customers\Infrastructure\ApiPlatform\State\Provider\CustomerItemProvider;
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
 * A company's customers (docs/SPEC.md § 4 customer). Read with customer.read, added and revised with customer.write;
 * deleted while unused or merged through the records engine with customer.remove, never through this resource. The shape is checked here, the company's preset and the rest by the use case.
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
                'kind[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['company', 'individual']]], description: 'Several kinds, OR\'d; a single `kind=company` still works.', constraints: []),
                'customerGroupId[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'uuid']], description: 'Several groups, OR\'d; a single `customerGroupId=…` still works.', constraints: []),
                'taxRegime[]' => new QueryParameter(schema: ['type' => 'array', 'items' => ['type' => 'string']], description: 'Several tax regimes by code, OR\'d, among those of the company\'s fiscal preset (`GET …/customer-options`).', constraints: []),
                'isActive' => new QueryParameter(schema: ['type' => 'boolean']),
                'toCheck' => new QueryParameter(schema: ['type' => 'boolean'], description: '« À vérifier »: marked by a person, or a business of the company\'s country without a number its preset requires; false, neither.'),
                'createdAt[from]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Created on or after this day, in the company\'s own calendar.'),
                'createdAt[to]' => new QueryParameter(schema: ['type' => 'string', 'format' => 'date'], description: 'Created on or before this day, in the company\'s own calendar.'),
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
            denormalizationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
            validationContext: ['groups' => [self::WRITE, RecordVersion::REVISE]],
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

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    /** The version read; a revision sends back the one it was opened at, and one saved since answers 409. */
    #[Assert\NotNull(groups: [RecordVersion::REVISE], payload: ['reason' => 'value_required'])]
    #[Assert\Positive(groups: [RecordVersion::REVISE])]
    #[Groups([self::READ, RecordVersion::REVISE])]
    public ?int $version = null;

    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Regex(pattern: Customer::NUMBER, groups: [self::WRITE], payload: ['reason' => 'invalid_number'])]
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

    #[Assert\NotBlank(groups: [self::WRITE], payload: ['reason' => 'value_required'])]
    #[Assert\Length(max: CustomerProfile::NAME_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_name'])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[Assert\Length(max: CustomerProfile::LEGAL_NAME_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_legal_name'])]
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
    public ?string $billingAddressLine1 = null;

    #[Assert\Length(max: PostalAddress::LINE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::LINE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingAddressLine2 = null;

    #[Assert\Length(max: PostalAddress::POSTAL_CODE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::POSTAL_CODE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingPostalCode = null;

    #[Assert\Length(max: PostalAddress::CITY_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::CITY_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingCity = null;

    #[ContactRule(ContactRule::COUNTRY, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $billingCountryCode = null;

    #[Assert\Length(max: PostalAddress::LINE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::LINE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingAddressLine1 = null;

    #[Assert\Length(max: PostalAddress::LINE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::LINE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingAddressLine2 = null;

    #[Assert\Length(max: PostalAddress::POSTAL_CODE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::POSTAL_CODE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingPostalCode = null;

    #[Assert\Length(max: PostalAddress::CITY_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => PostalAddress::CITY_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingCity = null;

    #[ContactRule(ContactRule::COUNTRY, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $shippingCountryCode = null;

    /** @var list<string> the ids of the company's taxes a new line for this customer starts with */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\All([new Assert\Uuid()], groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $defaultTaxComponentIds = [];

    /** A percentage from 0 to 100, as a decimal string. */
    #[Assert\Regex(pattern: '/^[0-9]{1,3}(\.[0-9]{1,3})?$/', groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $defaultDiscountRate = null;

    #[Assert\Length(max: CustomerProfile::NOTES_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => CustomerProfile::NOTES_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $notes = null;

    /** @var array<string, string|int|float|bool> values by the company's custom field keys (GET .../custom-fields?entity=customer) */
    #[ApiProperty(schema: ['type' => 'object', 'additionalProperties' => ['oneOf' => [['type' => 'string'], ['type' => 'number'], ['type' => 'boolean']]]])]
    #[Groups([self::READ, self::WRITE])]
    // count: not bounded yet ("Limits on the other lists", docs/plans/first-client.plan.md)
    public array $customFields = [];

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    /** « À vérifier » as a person set it, on the form or through a file's row. */
    #[Groups([self::READ, self::WRITE])]
    public bool $toCheck = false;

    /** What to look at, kept while the mark is on; dropped when it is taken off. */
    #[Assert\Length(max: CheckMark::NOTE_MAX, groups: [self::WRITE], payload: ['reason' => 'invalid_text', 'params' => ['max' => CheckMark::NOTE_MAX]])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $checkNote = null;

    /**
     * What the customer lacks, each clearing itself once fixed.
     *
     * @var list<string>
     */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['identifier_missing']]])]
    #[Groups([self::READ])]
    public array $checkReasons = [];

    /**
     * The registration numbers a business of the company's country lacks, by the preset's identifier key, which the
     * customer options name: what `identifier_missing` is about.
     *
     * @var list<string>
     */
    #[ApiProperty(writable: false, schema: ['type' => 'array', 'items' => ['type' => 'string']])]
    #[Groups([self::READ])]
    public array $missingIdentifiers = [];

    /**
     * The customer kept when this one was merged away: lists, reports and pickers show that one wherever this one is named.
     *
     * @var array{id: string, number: string, name: string}|null
     */
    #[ApiProperty(schema: ['anyOf' => [['type' => 'object', 'required' => ['id', 'number', 'name'], 'properties' => ['id' => ['type' => 'string', 'format' => 'uuid'], 'number' => ['type' => 'string'], 'name' => ['type' => 'string']]], ['type' => 'null']]])]
    #[Groups([self::READ])]
    public ?array $mergedInto = null;

    /** @param CustomerCheckScope $scope what « À vérifier » asks of the company's customers */
    public static function of(Customer $customer, CustomerCheckScope $scope): self
    {
        $profile = $customer->getProfile();
        $resource = new self();
        $resource->id = $customer->getId()->toRfc4122();
        $resource->version = $customer->getVersion();
        $resource->number = $customer->getNumber();
        $resource->kind = $profile->kind->value;
        $resource->customerGroupId = $customer->getGroup()?->getId()->toRfc4122();
        $resource->taxRegime = $customer->getTaxRegime()->getCode();
        $resource->name = $profile->name;
        $resource->legalName = $profile->legalName;
        $resource->identifiers = $profile->identifiers;
        $resource->email = $profile->email;
        $resource->phone = GroupedPhones::of($profile->phone);
        $resource->website = $profile->website;
        [$resource->billingAddressLine1, $resource->billingAddressLine2, $resource->billingPostalCode, $resource->billingCity, $resource->billingCountryCode] = $profile->billingAddress->parts();
        [$resource->shippingAddressLine1, $resource->shippingAddressLine2, $resource->shippingPostalCode, $resource->shippingCity, $resource->shippingCountryCode] = $profile->shippingAddress?->parts() ?? [null, null, null, null, null];
        $resource->defaultTaxComponentIds = $customer->getDefaultTaxComponentIds();
        $resource->defaultDiscountRate = $profile->defaultDiscountRate;
        $resource->notes = $profile->notes;
        $resource->customFields = $customer->getCustomFields();
        $resource->isActive = $customer->isActive();
        $mark = $customer->getCheckMark();
        $resource->toCheck = $mark->marked;
        $resource->checkNote = $mark->note;
        $resource->checkReasons = array_map(static fn (CustomerCheckReason $reason): string => $reason->value, $customer->checkReasons($scope));
        $resource->missingIdentifiers = $customer->missingIdentifiers($scope);
        $kept = $customer->getMergedInto();
        $resource->mergedInto = null === $kept ? null : ['id' => $kept->getId()->toRfc4122(), 'number' => $kept->getNumber(), 'name' => $kept->getProfile()->name];

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
            mark: new CheckMark($this->toCheck, $this->checkNote),
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
