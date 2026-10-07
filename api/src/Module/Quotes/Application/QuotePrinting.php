<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Application;

use App\Module\Quotes\Domain\Quote;
use App\Module\Quotes\Domain\QuotePrint;
use App\Settings\Application\DocumentFormats;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\SettingContext;

/**
 * How a quote prints today, and how long it binds, from its customer's settings and the company's formats. Sending
 * keeps what it prints with the quote; a draft reads it again on every render.
 */
final readonly class QuotePrinting
{
    public static function today(ReadSetting $settings, Quote $quote): QuotePrint
    {
        $context = self::context($quote);
        $language = $settings->value($context, 'document.language');

        return new QuotePrint(
            \is_string($language) ? $language : 'fr',
            true === $settings->value($context, QuoteSettings::SIGNATURE_BLOCK),
            DocumentFormats::print($settings, $context, $quote->getCompany()),
        );
    }

    /** The days a quote sent today binds its price, as its customer's settings say. */
    public static function validityDays(ReadSetting $settings, Quote $quote): int
    {
        $days = $settings->value(self::context($quote), QuoteSettings::VALIDITY_DAYS);

        return \is_int($days) ? $days : QuoteSettings::DEFAULT_VALIDITY_DAYS;
    }

    private static function context(Quote $quote): SettingContext
    {
        $customer = $quote->getCustomer();

        return new SettingContext($quote->getCompany(), customerGroupId: $customer->getGroup()?->getId(), customerId: $customer->getId());
    }
}
