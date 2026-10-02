<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Shared\Domain\CompanyOwned;
use App\Tenancy\Domain\Company;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A named set of prices that decides a line's unit price before any discount (docs/SPEC.md § 7, 2026-09-20): for every
 * customer, for the customers of one group, or for one customer, between two days or without end. Its customer and
 * group are held as ids: the lists are the Products module's, and a customer or a group is not.
 */
#[ORM\Entity]
#[ORM\Table(name: 'price_list')]
#[ORM\Index(name: 'idx_price_list_company', columns: ['company_id'])]
#[ORM\UniqueConstraint(name: 'uniq_price_list_company_name', columns: ['company_id', 'name'])]
class PriceList implements CompanyOwned
{
    public const int NAME_MAX = 120;
    public const int ITEMS_MAX = 1000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid')]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Company::class)]
    #[ORM\JoinColumn(name: 'company_id', nullable: false, onDelete: 'CASCADE')]
    private Company $company;

    #[ORM\Column(length: self::NAME_MAX)]
    private string $name;

    /** The group of customers it is for; none with a customer, or with everyone. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $customerGroupId = null;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $customerId = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    /** The last day it applies, inclusive. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validTo = null;

    #[ORM\Column]
    private bool $isActive = true;

    /** @var Collection<int, PriceListItem> */
    #[ORM\OneToMany(targetEntity: PriceListItem::class, mappedBy: 'priceList', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $items;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    private function __construct(Company $company, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->company = $company;
        $this->items = new ArrayCollection();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @throws InvalidPriceList */
    public static function create(Company $company, string $name, ?Uuid $customerGroupId, ?Uuid $customerId, ?\DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo, bool $isActive, \DateTimeImmutable $now): self
    {
        $list = new self($company, $now);
        $list->describe($name, $customerGroupId, $customerId, $validFrom, $validTo, $isActive);

        return $list;
    }

    /**
     * @return bool whether anything changed
     *
     * @throws InvalidPriceList
     */
    public function revise(string $name, ?Uuid $customerGroupId, ?Uuid $customerId, ?\DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo, bool $isActive, \DateTimeImmutable $now): bool
    {
        $before = $this->snapshot();
        $this->describe($name, $customerGroupId, $customerId, $validFrom, $validTo, $isActive);
        if ($before === $this->snapshot()) {
            return false;
        }
        $this->updatedAt = $now;

        return true;
    }

    /**
     * Makes the rows exactly these, whatever they were. A row is kept by its product and minimum and only repriced, so
     * a save changing a price, or swapping two, never inserts beside the row it replaces: the database holds one row
     * per product and minimum and checks it before a delete in the same flush could free the key.
     *
     * @param list<PriceListItem> $items
     *
     * @return bool whether anything changed
     *
     * @throws InvalidPriceList
     */
    public function replaceItems(array $items, \DateTimeImmutable $now): bool
    {
        if (\count($items) > self::ITEMS_MAX) {
            throw new InvalidPriceList('items', \sprintf('A price list holds at most %d prices.', self::ITEMS_MAX));
        }
        $seen = [];
        foreach ($items as $index => $item) {
            $key = $item->getProduct()->getId()->toRfc4122().'@'.$item->getMinQuantity();
            if (isset($seen[$key])) {
                throw new InvalidPriceList(\sprintf('items.%d.minQuantity', $index), 'A product has one price from each quantity.');
            }
            $seen[$key] = true;
        }
        $key = static fn (PriceListItem $item): string => $item->getProduct()->getId()->toRfc4122().'@'.$item->getMinQuantity();
        $held = [];
        foreach ($this->items as $row) {
            $held[$key($row)] = $row;
        }
        $changed = false;
        $wanted = [];
        foreach ($items as $item) {
            $wanted[$key($item)] = true;
            if (isset($held[$key($item)])) {
                $changed = $held[$key($item)]->reprice($item->getUnitPriceNet()) || $changed;
            } else {
                $this->items->add($item);
                $changed = true;
            }
        }
        foreach ($held as $heldKey => $row) {
            if (!isset($wanted[$heldKey])) {
                $this->items->removeElement($row);
                $changed = true;
            }
        }
        if ($changed) {
            $this->updatedAt = $now;
        }

        return $changed;
    }

    /** @throws InvalidPriceList */
    private function describe(string $name, ?Uuid $customerGroupId, ?Uuid $customerId, ?\DateTimeImmutable $validFrom, ?\DateTimeImmutable $validTo, bool $isActive): void
    {
        $name = trim($name);
        if ('' === $name || mb_strlen($name) > self::NAME_MAX) {
            throw new InvalidPriceList('name', \sprintf('A price list is named in 1 to %d characters.', self::NAME_MAX));
        }
        if (null !== $customerGroupId && null !== $customerId) {
            throw new InvalidPriceList('customerId', 'A price list is for one customer or for one group, not both.');
        }
        if (null !== $validFrom && null !== $validTo && $validTo < $validFrom) {
            throw new InvalidPriceList('validTo', 'A price list ends on or after the day it starts.');
        }
        $this->name = $name;
        $this->customerGroupId = $customerGroupId;
        $this->customerId = $customerId;
        $this->validFrom = $validFrom;
        $this->validTo = $validTo;
        $this->isActive = $isActive;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        return [
            'name' => $this->name,
            'customerGroupId' => $this->customerGroupId?->toRfc4122(),
            'customerId' => $this->customerId?->toRfc4122(),
            'validFrom' => $this->validFrom?->format('Y-m-d'),
            'validTo' => $this->validTo?->format('Y-m-d'),
            'isActive' => $this->isActive,
        ];
    }

    /**
     * What changed, by field name, between two snapshots: the audit keeps the names and never the values.
     *
     * @param array<string, mixed> $before
     *
     * @return list<string>
     */
    public function changesSince(array $before): array
    {
        $changed = [];
        foreach ($this->snapshot() as $field => $value) {
            if (($before[$field] ?? null) !== $value) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /** @return array<string, mixed> */
    public function describedAs(): array
    {
        return $this->snapshot();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCompany(): Company
    {
        return $this->company;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCustomerGroupId(): ?Uuid
    {
        return $this->customerGroupId;
    }

    public function getCustomerId(): ?Uuid
    {
        return $this->customerId;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function getValidTo(): ?\DateTimeImmutable
    {
        return $this->validTo;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /** @return list<PriceListItem> */
    public function getItems(): array
    {
        return array_values($this->items->toArray());
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
