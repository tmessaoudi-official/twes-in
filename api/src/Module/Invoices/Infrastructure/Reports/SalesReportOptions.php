<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Reports;

use App\Module\Reports\Application\ReportContext;
use App\Module\Reports\Application\ReportOption;

/**
 * The choices the sales reports offer, each defaulting to what they read before it: the net before each document's
 * discount beside the net (which stays the home's figure), and a product merged away counted on its own rather than
 * under the product kept.
 */
final class SalesReportOptions
{
    public static function netBeforeDiscount(): ReportOption
    {
        return new ReportOption('netBeforeDiscount', ['hidden', 'shown']);
    }

    public static function mergedProducts(): ReportOption
    {
        return new ReportOption('mergedProducts', ['kept', 'apart']);
    }

    public static function showsNetBeforeDiscount(ReportContext $context): bool
    {
        return 'shown' === self::chosen($context, self::netBeforeDiscount());
    }

    public static function mergedApart(ReportContext $context): bool
    {
        return 'apart' === self::chosen($context, self::mergedProducts());
    }

    /** The choice the address asked for the option, its first, today's reading, when it asked none. */
    private static function chosen(ReportContext $context, ReportOption $option): string
    {
        return $context->options[$option->name] ?? $option->choices[0];
    }
}
