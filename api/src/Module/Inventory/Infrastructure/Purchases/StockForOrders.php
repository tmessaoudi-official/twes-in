<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Purchases;

use App\Module\Inventory\Application\KeepStock;
use App\Module\Inventory\Application\OrderedGoods;
use App\Module\Inventory\Application\OrderedGoodsRefused;
use App\Module\Inventory\Application\ReceiptCost\ReceiptDocuments;
use App\Module\Inventory\Application\ReceiptCost\StockCostSettings;
use App\Module\Inventory\Domain\Exception\InvalidStockMovement;
use App\Module\Inventory\Domain\ValueObject\CostBasis;
use App\Module\Inventory\Domain\ValueObject\CostOnReceive;
use App\Module\Inventory\Domain\ValueObject\NamedLot;
use App\Module\Purchases\Application\IntakeCost;
use App\Module\Purchases\Application\IntakeDocument;
use App\Module\Purchases\Application\IntakeLine;
use App\Module\Purchases\Application\IntakeRefused;
use App\Module\Purchases\Application\StockIntake;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * Answers the purchases module's `StockIntake` port out of this module: each line of a receipt becomes a stock receipt
 * naming the line, from the order's vendor, with the vendor's delivery number and the day the goods came.
 */
final readonly class StockForOrders implements StockIntake
{
    /** The fields a stock refusal names, as the receipt's own lines name them. */
    private const array FIELDS = ['lot' => 'lotCode'];

    public function __construct(private KeepStock $stock, private ReceiptDocuments $documents, private ReadSetting $settings)
    {
    }

    public function receive(Company $company, IntakeDocument $document, array $lines, ?IntakeCost $apply, ?Uuid $actorUserId): void
    {
        try {
            $named = $this->documents->named($company, $document->vendorId->toRfc4122(), $document->supplierReference, $document->receivedOn->format('Y-m-d'), ofASentOrder: true);
        } catch (InvalidStockMovement $refused) {
            throw new IntakeRefused(null, $refused->field, $refused->getMessage(), $refused, $refused->reason, $refused->params);
        }
        if (null === $named) {
            throw new \LogicException('A receipt against an order always names its vendor and its day.');
        }
        $goods = array_map(static fn (IntakeLine $line): OrderedGoods => new OrderedGoods(
            $line->sourceId,
            $line->productId,
            $line->locationId,
            $line->quantity,
            null === $line->lotCode ? null : new NamedLot($line->lotCode, $line->lotExpiresOn),
            $line->unitCost,
            $line->costToComplete,
        ), $lines);

        try {
            $this->stock->receiveOrdered($company, $goods, $named, match ($apply) {
                IntakeCost::Average => CostBasis::Average,
                IntakeCost::Last => CostBasis::Last,
                null => null,
            }, $actorUserId);
        } catch (OrderedGoodsRefused $refused) {
            throw new IntakeRefused($refused->index, self::FIELDS[$refused->refusal->field] ?? $refused->refusal->field, $refused->getMessage(), $refused, $refused->refusal->reason, $refused->refusal->params);
        }
    }

    public function leavesCostToWhoReceives(Company $company): bool
    {
        return CostOnReceive::Suggest === StockCostSettings::modeOf($this->settings->value(new SettingContext($company), StockCostSettings::COST_ON_RECEIVE));
    }
}
