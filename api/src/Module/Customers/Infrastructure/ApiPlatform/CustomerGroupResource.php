<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Customers\Domain\CustomerGroup;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's customer groups. Read with customer.read, changed with customer.write; a group still holding customers
 * answers 409 to a DELETE, and each row says how many it holds.
 */
#[ApiResource(
    shortName: 'CustomerGroup',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/customer-groups',
            provider: CustomerGroupCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/customer-groups',
            processor: CreateCustomerGroupProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/customer-groups/{groupId}',
            processor: ReviseCustomerGroupProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/customer-groups/{groupId}',
            processor: DeleteCustomerGroupProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class CustomerGroupResource
{
    public const string READ = 'customer_group:read';
    public const string WRITE = 'customer_group:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: CustomerGroup::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    #[Assert\Length(max: CustomerGroup::DESCRIPTION_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $description = null;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $customerCount = 0;

    public static function of(CustomerGroup $group, int $customerCount): self
    {
        $resource = new self();
        $resource->id = $group->getId()->toRfc4122();
        $resource->name = $group->getName();
        $resource->description = $group->getDescription();
        $resource->customerCount = $customerCount;

        return $resource;
    }
}
