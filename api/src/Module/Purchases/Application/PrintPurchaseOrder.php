<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\DocumentNames;
use App\Fiscal\Domain\Calculation\QuantityTotals;
use App\Fiscal\Domain\Model\Unit;
use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\Model\PurchaseOrderLine;
use App\Module\Purchases\Domain\Repository\PurchaseOrderRepository;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderStatus;
use App\Module\Purchases\Domain\ValueObject\VendorSnapshot;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;
use App\Shared\Application\PdfRenderer;
use App\Shared\Application\PdfRenderingFailed;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\ValueObject\SellerSnapshot;
use Symfony\Component\Uid\Uuid;

/**
 * An order to a vendor as a PDF, rendered on every request and never stored. Once sent it prints as a sent quote does,
 * from what sending kept: the vendor and buyer of that day, its language, formats and design, and its units' names
 * unless the company keeps them live (`document.names_as_issued`), so the vendor's copy reads the same whatever changes
 * after. A draft prints as the settings say today across a watermark, as does a cancelled order, and so does an order
 * sent before orders kept how they print. Its language is the company's, a vendor having none of its own.
 */
final readonly class PrintPurchaseOrder
{
    public function __construct(
        private PurchaseOrderRepository $orders,
        private OrderVendors $vendors,
        private PurchaseOrderTemplate $template,
        private PdfRenderer $renderer,
        private ReadSetting $settings,
        private CurrencyScales $scales,
        private DocumentNames $names,
    ) {
    }

    /**
     * @throws PurchaseOrderNotFound
     * @throws PdfRenderingFailed
     */
    public function pdf(Company $company, Uuid $id): PrintedPurchaseOrder
    {
        $order = $this->orders->ofIdInCompany($id, $company->getId()) ?? throw new PurchaseOrderNotFound();
        $buyer = $order->getBuyerSnapshot() ?? SellerSnapshot::of($order->getCompany(), $order->getEstablishment());
        $printing = $order->getPrintSettings() ?? PurchaseOrderPrinting::today($this->settings, $order);
        $language = $printing->language;
        $units = array_map(static fn (PurchaseOrderLine $line): Unit => $line->getUnit(), $order->getLines());
        // No print of a sent order is kept, so each is a reprint: it names things as it was sent unless the company keeps
        // the names live.
        $kept = $order->getPrintedNames();
        $names = null !== $kept && true === $this->settings->value(new SettingContext($company), 'document.names_as_issued')
            ? $kept
            : $this->names->now($company, $language, $units);

        return new PrintedPurchaseOrder(self::fileName($order), $this->renderer->render($this->template->html(new PurchaseOrderPage(
            $order,
            PurchaseOrderTotals::of($order, $this->scales->of($buyer->currency)),
            $order->getVendorSnapshot() ?? $this->vendorToday($company, $order),
            $buyer,
            match ($order->getStatus()) {
                PurchaseOrderStatus::Draft => PurchaseOrderPage::DRAFT,
                PurchaseOrderStatus::Cancelled => PurchaseOrderPage::CANCELLED,
                PurchaseOrderStatus::Sent, PurchaseOrderStatus::PartlyReceived, PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed => null,
            },
            $language,
            $printing->print->dateFormat,
            $printing->print->numberFormat,
            $printing->print->design,
            QuantityTotals::ofUnits(array_map(static fn (PurchaseOrderLine $line): array => [$line->getUnit(), $line->getQuantity()], $order->getLines()), $names),
            $names,
        ))));
    }

    private function vendorToday(Company $company, PurchaseOrder $order): VendorSnapshot
    {
        // A vendor is retired, never removed, so a draft always finds its own.
        return VendorCard::of($this->vendors->ofId($company, $order->getVendorId()) ?? throw new \LogicException('The vendor of a draft order is gone.'));
    }

    private static function fileName(PurchaseOrder $order): string
    {
        $number = $order->getNumber();

        // A numbering format may carry slashes, which a file name may not.
        return null === $number ? \sprintf('purchase-order-%s.pdf', $order->getId()->toRfc4122()) : str_replace('/', '-', $number).'.pdf';
    }
}
