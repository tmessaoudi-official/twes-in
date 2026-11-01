<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ShortSales;

use App\Module\Inventory\Application\MoveStockForDeliveryNotes;
use App\Module\Inventory\Application\ShortSales\SaleWithoutStockSettings;
use App\Module\Inventory\Application\TellStockKeepers;
use App\Module\Inventory\Domain\Model\StockMovement;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\ShortSales\SaleStock;
use App\Shared\Application\ShortSales\Shortfall;
use App\Shared\Application\ShortSales\ShortLine;
use App\Shared\Application\ShortSales\ShortSaleConsent;
use App\Shared\Application\ShortSales\ShortSaleRule;
use App\Shared\Application\ShortSales\SoldDocument;
use App\Tenancy\Domain\Model\Company;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The stock module's answer to a sale: its rule for the seller's role at the establishment, and the products short
 * there, read only when the rule does not allow any sale. Where it refuses or asks, the sale's stock is taken out here,
 * under the stock's locks, inside the document's transaction; what it left out is told as the move after the commit
 * tells it (MoveStockOnDeliveryNotes, MoveStockOnInvoices), which then finds the stock moved and takes nothing more.
 */
final readonly class InventorySaleStock implements SaleStock
{
    public function __construct(private ReadSetting $settings, private MoveStockForDeliveryNotes $move, private TellStockKeepers $tell, private LoggerInterface $logger)
    {
    }

    public function shortfall(Company $company, Uuid $establishmentId, ?Uuid $roleId, array $lines): Shortfall
    {
        $rule = $this->rule($company, $establishmentId, $roleId);

        return ShortSaleRule::Allow === $rule ? new Shortfall($rule) : new Shortfall($rule, $this->move->shortOf($company->getId(), $establishmentId, $lines));
    }

    public function takeOut(Company $company, Uuid $establishmentId, ShortSaleConsent $consent, SoldDocument $document, array $lines): void
    {
        $rule = $this->rule($company, $establishmentId, $consent->roleId);
        if (ShortSaleRule::Allow === $rule || [] === $lines) {
            return;
        }
        $reasons = $this->move->soldUnderLocks(
            $document->invoice ? StockMovement::SOURCE_INVOICE : StockMovement::SOURCE_DELIVERY_NOTE,
            $document->id,
            $company->getId(),
            $establishmentId,
            $lines,
            static function (array $short) use ($rule, $consent): void {
                /** @var list<ShortLine> $short */
                $refused = new Shortfall($rule, $short)->refusal($consent);
                if (null !== $refused) {
                    throw $refused;
                }
            },
        );
        foreach ($reasons as $reason) {
            $this->logger->warning(($document->invoice ? 'Invoice' : 'Delivery note').' {number} left a line out of stock: {reason}.', ['number' => $document->number, 'reason' => $reason]);
        }
        if ([] !== $reasons) {
            $document->invoice
                ? $this->tell->invoiceLeftLinesOut($company->getId(), $document->id, $document->number)
                : $this->tell->deliveryNoteLeftLinesOut($company->getId(), $document->id, $document->number);
        }
    }

    private function rule(Company $company, Uuid $establishmentId, ?Uuid $roleId): ShortSaleRule
    {
        return SaleWithoutStockSettings::ruleOf($this->settings->value(new SettingContext($company, $roleId, establishmentId: $establishmentId), SaleWithoutStockSettings::KEY));
    }
}
