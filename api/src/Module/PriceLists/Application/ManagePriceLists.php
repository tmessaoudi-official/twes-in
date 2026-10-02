<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Customers\Domain\CustomerGroupRepository;
use App\Module\Customers\Domain\CustomerRepository;
use App\Module\PriceLists\Domain\InvalidPriceList;
use App\Module\PriceLists\Domain\PriceList;
use App\Module\PriceLists\Domain\PriceListItem;
use App\Module\PriceLists\Domain\PriceListRepository;
use App\Module\Products\Domain\ProductRepository;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's price lists (docs/SPEC.md § 7, 2026-09-20): listed by name, each name used once, each for everyone, one
 * customer's group or one customer, and written with the prices it holds as one document, so a save makes them exactly
 * these. Audited with the names of the fields a change touched and never the prices.
 */
final readonly class ManagePriceLists
{
    public const string ENTITY_TYPE = 'price_list';
    public const string CREATED = 'price_list.created';
    public const string REVISED = 'price_list.revised';
    public const string DELETED = 'price_list.deleted';

    public function __construct(
        private PriceListRepository $lists,
        private ProductRepository $products,
        private CustomerRepository $customers,
        private CustomerGroupRepository $groups,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /** @return list<PriceList> */
    public function list(Company $company): array
    {
        return $this->lists->ofCompany($company->getId());
    }

    /** @throws PriceListNotFound */
    public function get(Company $company, Uuid $id): PriceList
    {
        return $this->lists->ofIdInCompany($id, $company->getId()) ?? throw new PriceListNotFound();
    }

    /**
     * @throws PriceListNameTaken
     * @throws InvalidPriceList
     */
    public function create(Company $company, PriceListInput $input, ?Uuid $actorUserId): PriceList
    {
        return $this->transactions->run(function () use ($company, $input, $actorUserId): PriceList {
            if (null !== $this->lists->ofNameInCompany(trim($input->name), $company->getId())) {
                throw new PriceListNameTaken();
            }
            $this->checkScope($company, $input);
            $now = $this->clock->now();
            $list = PriceList::create($company, $input->name, $input->customerGroupId, $input->customerId, $input->validFrom, $input->validTo, $input->isActive, $now);
            $list->replaceItems($this->itemsOf($company, $list, $input->items ?? []), $now);
            $this->lists->save($list);
            $this->record($company, $list->getId(), self::CREATED, [], $actorUserId);

            return $list;
        });
    }

    /**
     * @throws PriceListNotFound
     * @throws PriceListNameTaken
     * @throws InvalidPriceList
     */
    public function revise(Company $company, Uuid $id, PriceListInput $input, ?Uuid $actorUserId): PriceList
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId): PriceList {
            $list = $this->get($company, $id);
            $holder = $this->lists->ofNameInCompany(trim($input->name), $company->getId());
            if (null !== $holder && !$holder->getId()->equals($list->getId())) {
                throw new PriceListNameTaken();
            }
            $this->checkScope($company, $input);
            $now = $this->clock->now();
            $before = $list->describedAs();
            $fields = $list->revise($input->name, $input->customerGroupId, $input->customerId, $input->validFrom, $input->validTo, $input->isActive, $now) ? $list->changesSince($before) : [];
            if (null !== $input->items && $list->replaceItems($this->itemsOf($company, $list, $input->items), $now)) {
                $fields[] = 'items';
            }
            if ([] !== $fields) {
                $this->lists->save($list);
                $this->record($company, $list->getId(), self::REVISED, ['fields' => $fields], $actorUserId);
            }

            return $list;
        });
    }

    /** @throws PriceListNotFound */
    public function delete(Company $company, Uuid $id, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($company, $id, $actorUserId): void {
            $list = $this->get($company, $id);
            $this->lists->remove($list);
            $this->record($company, $id, self::DELETED, [], $actorUserId);
        });
    }

    /** @throws InvalidPriceList */
    private function checkScope(Company $company, PriceListInput $input): void
    {
        if (null !== $input->customerId && null === $this->customers->ofIdInCompany($input->customerId, $company->getId())) {
            throw new InvalidPriceList('customerId', 'No customer of this company has this id.');
        }
        if (null !== $input->customerGroupId && null === $this->groups->ofIdInCompany($input->customerGroupId, $company->getId())) {
            throw new InvalidPriceList('customerGroupId', 'No customer group of this company has this id.');
        }
    }

    /**
     * @param list<PriceListItemInput> $rows
     *
     * @return list<PriceListItem>
     *
     * @throws InvalidPriceList
     */
    private function itemsOf(Company $company, PriceList $list, array $rows): array
    {
        $ids = array_values(array_unique(array_map(static fn (PriceListItemInput $row): string => $row->productId->toRfc4122(), $rows)));
        $products = [];
        foreach ($this->products->ofIdsInCompany(array_map(Uuid::fromString(...), $ids), $company->getId()) as $product) {
            $products[$product->getId()->toRfc4122()] = $product;
        }
        $items = [];
        foreach ($rows as $index => $row) {
            $product = $products[$row->productId->toRfc4122()] ?? throw new InvalidPriceList(\sprintf('items.%d.productId', $index), 'No product of this company has this id.');
            try {
                $items[] = new PriceListItem($list, $product, $row->minQuantity, $row->unitPriceNet);
            } catch (InvalidPriceList $refused) {
                throw new InvalidPriceList(\sprintf('items.%d.%s', $index, $refused->field), $refused->getMessage());
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $listId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $listId, $action, $actorUserId, $changes, $company->getId()));
    }
}
