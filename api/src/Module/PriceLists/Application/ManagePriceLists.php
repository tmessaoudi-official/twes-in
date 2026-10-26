<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\PriceLists\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Module\Customers\Domain\Repository\CustomerGroupRepository;
use App\Module\Customers\Domain\Repository\CustomerRepository;
use App\Module\PriceLists\Domain\Exception\InvalidPriceList;
use App\Module\PriceLists\Domain\Model\PriceList;
use App\Module\PriceLists\Domain\Model\PriceListItem;
use App\Module\PriceLists\Domain\Repository\PriceListRepository;
use App\Module\Products\Domain\Repository\ProductRepository;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\TypedText;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's price lists (docs/SPEC.md § 7, 2026-09-20): listed by name, each name used once, each for everyone, one
 * customer's group or one customer, and written with the prices it holds as one document, so a save makes them exactly
 * these. Audited with the names of the fields a change touched and never the prices.
 */
final readonly class ManagePriceLists implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'price_list';
    public const string CREATED = 'price_list.created';
    public const string REVISED = 'price_list.revised';

    public function __construct(
        private PriceListRepository $lists,
        private ProductRepository $products,
        private CustomerRepository $customers,
        private CustomerGroupRepository $groups,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private RecordVersions $versions,
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
            if (null !== $this->lists->ofNameInCompany(TypedText::trimmed($input->name), $company->getId())) {
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
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $id, PriceListInput $input, ?Uuid $actorUserId, ?int $version = null): PriceList
    {
        return $this->transactions->run(function () use ($company, $id, $input, $actorUserId, $version): PriceList {
            $list = $this->get($company, $id);
            if (null !== $version) {
                $this->versions->expect($list, $version);
            }
            $holder = $this->lists->ofNameInCompany(TypedText::trimmed($input->name), $company->getId());
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

    /** @throws InvalidPriceList */
    private function checkScope(Company $company, PriceListInput $input): void
    {
        $customer = null === $input->customerId ? null : $this->customers->ofIdInCompany($input->customerId, $company->getId());
        if (null !== $input->customerId && null === $customer) {
            throw new InvalidPriceList('customerId', 'No customer of this company has this id.', 'unknown_choice');
        }
        if (null !== $customer && $customer->isMergedAway()) {
            throw new InvalidPriceList('customerId', \sprintf('The customer %s was merged into %s: a price list is for the customer kept.', $customer->getNumber(), $customer->standingFor()->getNumber()), 'customer_merged_choose', ['number' => $customer->getNumber(), 'kept' => $customer->standingFor()->getNumber()]);
        }
        if (null !== $input->customerGroupId && null === $this->groups->ofIdInCompany($input->customerGroupId, $company->getId())) {
            throw new InvalidPriceList('customerGroupId', 'No customer group of this company has this id.', 'unknown_group_id');
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
            $product = $products[$row->productId->toRfc4122()] ?? throw new InvalidPriceList(\sprintf('items.%d.productId', $index), 'No product of this company has this id.', 'unknown_choice');
            try {
                $items[] = new PriceListItem($list, $product, $row->minQuantity, $row->unitPriceNet);
            } catch (InvalidPriceList $refused) {
                throw new InvalidPriceList(\sprintf('items.%d.%s', $index, $refused->field), $refused->getMessage(), $refused->reason, $refused->params);
            }
        }

        return $items;
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $listId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $listId, $action, $actorUserId, $changes, $company->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::CREATED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
            self::REVISED => new Recorded(self::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
