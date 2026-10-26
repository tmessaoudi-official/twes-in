<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Fiscal\Domain\Repository\UnitRepository;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Domain\Exception\PurchaseOrderTransitionRefused;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\Model\PurchaseOrderLine;
use App\Module\Purchases\Domain\Repository\PurchaseOrderRepository;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderLineDetails;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderSearch;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Domain\Repository\EstablishmentRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company's orders to its vendors while they are drafts. An order goes to one of its active vendors from one of its
 * establishments; a line orders one of its active goods, in the product's own unit. A vendor or product retired since
 * stays with the draft that already names it. What one unit should cost is a cost, so it is written and read only by
 * whoever may read costs (`$seesCost`): for anyone else a line keeps the price the draft already had for its product,
 * and a new line has none. Audited with the names of the fields a revision changed, never their values.
 */
final readonly class ManagePurchaseOrders implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'purchase_order';
    public const string CREATED = 'purchase_order.created';
    public const string REVISED = 'purchase_order.revised';

    public function __construct(
        private PurchaseOrderRepository $orders,
        private Transactions $transactions,
        private OrderVendors $vendors,
        private OrderProducts $products,
        private UnitRepository $units,
        private EstablishmentRepository $establishments,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private RecordVersions $versions,
    ) {
    }

    /**
     * One page of the company's orders, searched, narrowed and sorted by the database.
     *
     * @return Page<PurchaseOrder>
     */
    public function search(Company $company, PurchaseOrderSearch $search, PageRequest $page): Page
    {
        return $this->orders->search($company->getId(), $this->takingInMergedAway($company, $search), $page);
    }

    /** @return array{all: int, statuses: array<string, int>} */
    public function statusCounts(Company $company, PurchaseOrderSearch $search): array
    {
        return $this->orders->statusCounts($company->getId(), $this->takingInMergedAway($company, $search));
    }

    /** A vendor's orders are also those placed with a vendor merged into it since. */
    private function takingInMergedAway(Company $company, PurchaseOrderSearch $search): PurchaseOrderSearch
    {
        return [] === $search->vendors ? $search : $search->forVendors($this->vendors->withMergedAway($company, $search->vendors));
    }

    /** @throws PurchaseOrderNotFound */
    public function get(Company $company, Uuid $id): PurchaseOrder
    {
        return $this->orders->ofIdInCompany($id, $company->getId()) ?? throw new PurchaseOrderNotFound();
    }

    /** @throws InvalidPurchaseOrder */
    public function create(Company $company, PurchaseOrderInput $input, bool $seesCost, ?Uuid $actorUserId): PurchaseOrder
    {
        return $this->transactions->run(function () use ($company, $input, $seesCost, $actorUserId): PurchaseOrder {
            [$establishment, $vendor, $lines] = $this->checked($company, $input, $seesCost, null);
            $order = PurchaseOrder::create($company, $establishment, $vendor->id, $vendor->name, $input->header, $lines, $this->clock->now());
            $order->keepVendorCodes($this->vendorCodes($company, $order));
            $this->orders->save($order);
            $this->record($company, $order->getId(), self::CREATED, [], $actorUserId);

            return $order;
        });
    }

    /**
     * `$version` is the one the editor opened; a save over a later one is refused before anything is changed.
     *
     * @throws PurchaseOrderNotFound
     * @throws PurchaseOrderTransitionRefused
     * @throws InvalidPurchaseOrder
     * @throws RecordChanged
     */
    public function revise(Company $company, Uuid $id, PurchaseOrderInput $input, bool $seesCost, ?Uuid $actorUserId, ?int $version = null): PurchaseOrder
    {
        return $this->transactions->run(function () use ($company, $id, $input, $seesCost, $actorUserId, $version): PurchaseOrder {
            // Held until this transaction ends and read as it stands: a draft check on it cannot be overtaken.
            $order = $this->orders->lockedOfIdInCompany($id, $company->getId()) ?? throw new PurchaseOrderNotFound();
            if (null !== $version) {
                $this->versions->expect($order, $version);
            }
            $order->assertDraft('changes');
            [$establishment, $vendor, $lines] = $this->checked($company, $input, $seesCost, $order);
            $changed = $order->revise($establishment, $vendor->id, $vendor->name, $input->header, $lines, $this->clock->now());
            $order->keepVendorCodes($this->vendorCodes($company, $order));
            $this->orders->save($order);
            if ([] !== $changed) {
                $this->record($company, $order->getId(), self::REVISED, ['fields' => $changed], $actorUserId);
            }

            return $order;
        });
    }

    /**
     * What the input names, found in the company and checked; what the draft already names is kept even retired.
     *
     * @return array{Establishment, OrderVendor, list<PurchaseOrderLineDetails>}
     *
     * @throws InvalidPurchaseOrder
     */
    private function checked(Company $company, PurchaseOrderInput $input, bool $seesCost, ?PurchaseOrder $current): array
    {
        $vendor = $this->vendors->ofId($company, $input->vendorId)
            ?? throw new InvalidPurchaseOrder('vendorId', 'No vendor of this company has this id.');
        if (!$vendor->active && !(null !== $current && $current->getVendorId()->equals($vendor->id))) {
            throw new InvalidPurchaseOrder('vendorId', \sprintf('The vendor %s is retired.', $vendor->number));
        }

        if (null === $input->establishmentId) {
            $establishment = array_find($this->establishments->ofCompany($company->getId()), static fn (Establishment $e): bool => $e->isDefault())
                ?? throw new \LogicException('A company always has a default establishment.');
        } else {
            $establishment = $this->establishments->ofIdInCompany($input->establishmentId, $company->getId())
                ?? throw new InvalidPurchaseOrder('establishmentId', 'No establishment of this company has this id.');
        }

        $products = [];
        foreach ($this->products->ofIds($company, array_map(static fn (PurchaseOrderLineInput $line): Uuid => $line->productId, $input->lines)) as $product) {
            $products[$product->id->toRfc4122()] = $product;
        }
        $kept = [];
        foreach ($current?->getLines() ?? [] as $line) {
            $kept[$line->getProductId()->toRfc4122()] ??= $line;
        }

        $lines = [];
        foreach ($input->lines as $index => $line) {
            try {
                $lines[] = $this->line($company, $line, $products[$line->productId->toRfc4122()] ?? null, $kept[$line->productId->toRfc4122()] ?? null, $seesCost);
            } catch (InvalidPurchaseOrder $refused) {
                throw $refused->within("lines[$index]");
            }
        }

        return [$establishment, $vendor, $lines];
    }

    /** @throws InvalidPurchaseOrder */
    private function line(Company $company, PurchaseOrderLineInput $line, ?OrderProduct $product, ?PurchaseOrderLine $kept, bool $seesCost): PurchaseOrderLineDetails
    {
        if (null === $product) {
            throw new InvalidPurchaseOrder('productId', 'No product of this company has this id.');
        }
        if (!$product->goods) {
            throw new InvalidPurchaseOrder('productId', \sprintf('The product %s is a service: only goods are ordered from a vendor here.', $product->reference));
        }
        if (!$product->active && null === $kept) {
            throw new InvalidPurchaseOrder('productId', null === $product->keptReference ? \sprintf('The product %s is deactivated.', $product->reference) : \sprintf('The product %s was merged into %s: a new document names the product kept.', $product->reference, $product->keptReference));
        }
        $unit = $this->units->ofIdInCompany($product->unitId, $company->getId())
            ?? throw new \LogicException('A product is counted in a unit of its own company.');
        // A retired unit still counts the products already counted in it.
        $description = null === $line->description || '' === TypedText::trimmed($line->description) ? $product->name : $line->description;
        $price = $seesCost ? ($line->unitPriceNet ?? (null === $kept ? $product->costPrice : null)) : $kept?->getUnitPriceNet();

        return new PurchaseOrderLineDetails($product->id, $product->reference, $description, $line->quantity, $unit, $price);
    }

    /** @return array<string, string> the vendor's own code for the order's products, as the vendor it names has them */
    private function vendorCodes(Company $company, PurchaseOrder $order): array
    {
        return $this->products->vendorCodes($company, $order->getVendorId(), array_map(static fn (PurchaseOrderLine $line): Uuid => $line->getProductId(), $order->getLines()));
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, Uuid $orderId, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(self::ENTITY_TYPE, $orderId, $action, $actorUserId, $changes, $company->getId()));
    }

    public function auditActions(): array
    {
        return [
            self::CREATED => AuditScope::Company,
            self::REVISED => AuditScope::Company,
        ];
    }
}
