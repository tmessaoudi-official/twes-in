<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderPrint;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;

/**
 * How an order prints today: the company's document language and formats, a vendor having no settings of its own.
 * Sending keeps it with the order; a draft reads it again on every render.
 */
final readonly class PurchaseOrderPrinting
{
    public static function today(ReadSetting $settings, PurchaseOrder $order): PurchaseOrderPrint
    {
        $company = $order->getCompany();
        $context = new SettingContext($company);

        return new PurchaseOrderPrint($settings->text($context, 'document.language'), DocumentFormats::print($settings, $context, $company));
    }
}
