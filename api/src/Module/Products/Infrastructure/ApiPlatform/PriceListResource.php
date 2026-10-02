<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Module\Products\Application\PriceListInput;
use App\Module\Products\Domain\PriceList;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A company's price lists (docs/SPEC.md § 7, 2026-09-20): each for everyone, for the customers of one group or for one
 * customer, between two days or without end, holding the net unit price of a product from a quantity up. Read with
 * product.read, changed with product.write. A save writes the list and, when it sends `items`, makes its prices
 * exactly those; leaving `items` out keeps them. The collection answers each list with its number of prices and no
 * rows. A name another list of the company has answers 409; a row, a day or a scope that is refused answers 422 naming
 * the field (`items.<index>.<field>`).
 */
#[ApiResource(
    shortName: 'PriceList',
    operations: [
        new GetCollection(
            uriTemplate: '/companies/{companyId}/price-lists',
            provider: PriceListCollectionProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Get(
            uriTemplate: '/companies/{companyId}/price-lists/{priceListId}',
            provider: PriceListItemProvider::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
        ),
        new Post(
            uriTemplate: '/companies/{companyId}/price-lists',
            processor: CreatePriceListProcessor::class,
            security: 'is_granted("ROLE_USER")',
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Put(
            uriTemplate: '/companies/{companyId}/price-lists/{priceListId}',
            processor: RevisePriceListProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
            normalizationContext: ['groups' => [self::READ]],
            denormalizationContext: ['groups' => [self::WRITE]],
            validationContext: ['groups' => [self::WRITE]],
        ),
        new Delete(
            uriTemplate: '/companies/{companyId}/price-lists/{priceListId}',
            processor: DeletePriceListProcessor::class,
            security: 'is_granted("ROLE_USER")',
            read: false,
        ),
    ],
)]
final class PriceListResource
{
    public const string READ = 'price_list:read';
    public const string WRITE = 'price_list:write';

    #[ApiProperty(identifier: false, writable: false)]
    #[Groups([self::READ])]
    public ?string $id = null;

    #[Assert\NotBlank(groups: [self::WRITE])]
    #[Assert\Length(max: PriceList::NAME_MAX, groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public string $name = '';

    /** The customer group it is for (GET .../customer-groups); null for a customer's list or everyone's. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $customerGroupId = null;

    /** The one customer it is for; null for a group's list or everyone's. */
    #[Assert\Uuid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $customerId = null;

    /** The first day it applies, as `YYYY-MM-DD`; null from the start. */
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $validFrom = null;

    /** The last day it applies, inclusive; null without end. */
    #[Assert\Date(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?string $validTo = null;

    #[Groups([self::READ, self::WRITE])]
    public bool $isActive = true;

    #[ApiProperty(writable: false)]
    #[Groups([self::READ])]
    public int $itemCount = 0;

    /**
     * Its prices; sent on a save they become exactly these, left out they stay. Read on one list, not in the collection.
     *
     * @var list<PriceListItemRow>|null
     */
    #[Assert\Type('list', groups: [self::WRITE])]
    #[Assert\Count(max: PriceList::ITEMS_MAX, groups: [self::WRITE])]
    #[Assert\Valid(groups: [self::WRITE])]
    #[Groups([self::READ, self::WRITE])]
    public ?array $items = null;

    public static function of(PriceList $list, bool $withItems): self
    {
        $resource = new self();
        $resource->id = $list->getId()->toRfc4122();
        $resource->name = $list->getName();
        $resource->customerGroupId = $list->getCustomerGroupId()?->toRfc4122();
        $resource->customerId = $list->getCustomerId()?->toRfc4122();
        $resource->validFrom = $list->getValidFrom()?->format('Y-m-d');
        $resource->validTo = $list->getValidTo()?->format('Y-m-d');
        $resource->isActive = $list->isActive();
        $resource->itemCount = \count($list->getItems());
        $resource->items = $withItems ? array_map(PriceListItemRow::of(...), $list->getItems()) : null;

        return $resource;
    }

    public function input(): PriceListInput
    {
        return new PriceListInput(
            $this->name,
            null === $this->customerGroupId ? null : Uuid::fromString($this->customerGroupId),
            null === $this->customerId ? null : Uuid::fromString($this->customerId),
            null === $this->validFrom ? null : new \DateTimeImmutable($this->validFrom),
            null === $this->validTo ? null : new \DateTimeImmutable($this->validTo),
            $this->isActive,
            null === $this->items ? null : array_map(static fn (PriceListItemRow $row) => $row->input(), $this->items),
        );
    }
}
