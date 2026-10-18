<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Module\Purchases\Domain\Exception\InvalidPurchaseOrder;
use App\Module\Purchases\Domain\Exception\PurchaseOrderTransitionRefused;
use App\Module\Purchases\Domain\Model\GoodsReceipt;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\Model\PurchaseOrderLine;
use App\Module\Purchases\Domain\Repository\PurchaseOrderRepository;
use App\Module\Purchases\Domain\ValueObject\ReceivedGoods;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Numbering\AllocateNumber;
use App\Tenancy\Application\Numbering\NoNumberingSeries;
use App\Tenancy\Domain\Exception\InvalidNumbering;
use App\Tenancy\Domain\Model\Company;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An order past its draft: sent (numbered, in the transaction that stores it, keeping who the vendor was that day),
 * received in parts, each receipt putting its goods on the shelves in the same transaction, then closed short or
 * cancelled when nothing came. The order is held for every move, so two receipts typed at once cannot both bring the
 * last of a line. Every move is audited.
 */
final readonly class PurchaseOrderWorkflow
{
    public const string DOCUMENT_TYPE = 'purchase_order';
    public const string SENT = 'purchase_order.sent';
    public const string RECEIVED = 'purchase_order.received';
    public const string CLOSED = 'purchase_order.closed';
    public const string CANCELLED = 'purchase_order.cancelled';

    public function __construct(
        private PurchaseOrderRepository $orders,
        private OrderVendors $vendors,
        private OrderProducts $products,
        private StockIntake $stock,
        private AllocateNumber $numbers,
        private Transactions $transactions,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PurchaseOrderNotFound
     * @throws PurchaseOrderTransitionRefused
     * @throws InvalidPurchaseOrder
     * @throws NoNumberingSeries              when the order's establishment numbers no purchase order
     * @throws InvalidNumbering               when the company's day comes before the month of the series' last number
     * @throws PurchaseOrderNumberTaken       when another establishment of the company already gave the number
     */
    public function send(Company $company, Uuid $id, ?Uuid $actorUserId): PurchaseOrder
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): PurchaseOrder {
            // Held and read as it stands before the series: a request that read the draft before another sent it is
            // refused here, and takes no number.
            $order = $this->locked($company, $id);
            $order->assertDraft('is sent');
            if ([] === $order->getLines()) {
                throw new InvalidPurchaseOrder('lines', 'An order is sent with at least one line.');
            }
            $vendor = $this->vendors->ofId($company, $order->getVendorId())
                ?? throw new InvalidPurchaseOrder('vendorId', 'The vendor of this order is gone: choose another.');
            $allocated = $this->numbers->allocate($company, $order->getEstablishment(), self::DOCUMENT_TYPE);
            if ($this->orders->numberTaken($company->getId(), $allocated->number)) {
                throw new PurchaseOrderNumberTaken(\sprintf('The number %s is already on another purchase order of this company: give the purchase order series of establishment %s a format with {EST}, so that establishments number apart.', $allocated->number, $order->getEstablishment()->getCode()));
            }
            // The vendor's codes as the order goes out, whatever was last read when the draft was saved.
            $order->keepVendorCodes($this->products->vendorCodes($company, $order->getVendorId(), array_map(static fn (PurchaseOrderLine $line): Uuid => $line->getProductId(), $order->getLines())));
            $order->send($allocated->number, $allocated->issueDate, VendorCard::of($vendor), $this->clock->now());
            $this->orders->save($order);
            $this->record($company, $order, self::SENT, ['number' => $allocated->number], $actorUserId);

            return $order;
        });
    }

    /**
     * Records goods that arrived on `$receivedOn`, a day of the company's, and puts them on the shelves. What one unit
     * cost is the person's to say when they may read costs, and the order's price when they leave it out; for anyone
     * else it is the order's price, and a line the order gave no price is left for a cost reader to complete.
     *
     * @param list<ReceivedGoods> $goods
     *
     * @throws PurchaseOrderNotFound
     * @throws PurchaseOrderTransitionRefused
     * @throws InvalidPurchaseOrder
     */
    public function receive(Company $company, Uuid $id, \DateTimeImmutable $receivedOn, ?string $supplierReference, array $goods, bool $seesCost, ?IntakeCost $apply, ?Uuid $actorUserId): GoodsReceipt
    {
        return $this->transactions->run(function () use ($company, $id, $receivedOn, $supplierReference, $goods, $seesCost, $apply, $actorUserId): GoodsReceipt {
            $order = $this->locked($company, $id);
            $now = $this->clock->now();
            $today = new \DateTimeImmutable($now->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));
            if (!$seesCost) {
                // Whoever may not read costs gives none: a cost sent anyway is not theirs to set.
                $goods = array_map(static fn (ReceivedGoods $part): ReceivedGoods => new ReceivedGoods($part->orderLineId, $part->locationId, $part->quantity, $part->lotCode, $part->lotExpiresOn), $goods);
            }
            $receipt = $order->receive($receivedOn, $today, $supplierReference, $goods, $actorUserId, $now);

            $lines = [];
            foreach ($receipt->getLines() as $line) {
                $cost = $line->getUnitCost() ?? $line->getOrderLine()->getUnitPriceNet();
                $lines[] = new IntakeLine($line->getId(), $line->getOrderLine()->getProductId(), $line->getLocationId(), $line->getQuantity(), $line->getLotCode(), $line->getLotExpiresOn(), $cost, !$seesCost && null === $cost);
            }
            if ([] === $lines) {
                throw new \LogicException('The order records no receipt without lines.');
            }
            try {
                $this->stock->receive($company, new IntakeDocument($order->getVendorId(), $receipt->getSupplierReference(), $receipt->getReceivedOn()), $lines, $seesCost ? $apply : null, $actorUserId);
            } catch (IntakeRefused $refused) {
                throw new InvalidPurchaseOrder(null === $refused->index ? $refused->field : \sprintf('lines[%d].%s', $refused->index, $refused->field), $refused->getMessage());
            }
            $this->orders->save($order);
            $this->record($company, $order, self::RECEIVED, ['receiptId' => $receipt->getId()->toRfc4122(), 'status' => $order->getStatus()->value], $actorUserId);

            return $receipt;
        });
    }

    /**
     * @throws PurchaseOrderNotFound
     * @throws PurchaseOrderTransitionRefused
     */
    public function close(Company $company, Uuid $id, ?Uuid $actorUserId): PurchaseOrder
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): PurchaseOrder {
            $order = $this->locked($company, $id);
            $order->close($this->clock->now());
            $this->orders->save($order);
            $this->record($company, $order, self::CLOSED, [], $actorUserId);

            return $order;
        });
    }

    /**
     * @throws PurchaseOrderNotFound
     * @throws PurchaseOrderTransitionRefused
     */
    public function cancel(Company $company, Uuid $id, ?Uuid $actorUserId): PurchaseOrder
    {
        return $this->transactions->run(function () use ($company, $id, $actorUserId): PurchaseOrder {
            $order = $this->locked($company, $id);
            $order->cancel($this->clock->now());
            $this->orders->save($order);
            $this->record($company, $order, self::CANCELLED, [], $actorUserId);

            return $order;
        });
    }

    private function locked(Company $company, Uuid $id): PurchaseOrder
    {
        return $this->orders->lockedOfIdInCompany($id, $company->getId()) ?? throw new PurchaseOrderNotFound();
    }

    /** @param array<string, mixed> $changes */
    private function record(Company $company, PurchaseOrder $order, string $action, array $changes, ?Uuid $actorUserId): void
    {
        $this->audit->record(new AuditEntry(ManagePurchaseOrders::ENTITY_TYPE, $order->getId(), $action, $actorUserId, $changes, $company->getId()));
    }
}
