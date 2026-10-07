<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Application\DepositDeductions;
use App\Module\Invoices\Application\InvoiceTotals;
use App\Module\Invoices\Domain\InvoiceRepository;
use App\Module\Invoices\Infrastructure\Pdf\TranslatorDepositWording;
use App\Settings\Application\BusinessDefaultSettings;
use App\Settings\Application\ReadSetting;
use App\Settings\Application\ResolveSettings;
use App\Settings\Application\SettingCatalog;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/** Deposits given back with the wording the API ships, `translations/pdf.<language>.yaml`, and default settings. */
final class ShippedDepositDeductions
{
    public static function of(InvoiceRepository $invoices, InvoiceTotals $totals): DepositDeductions
    {
        $translator = new Translator('fr');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (['fr', 'en'] as $language) {
            $translator->addResource('yaml', __DIR__.'/../../translations/pdf.'.$language.'.yaml', $language, 'pdf');
        }

        return new DepositDeductions($invoices, $totals, new TranslatorDepositWording($translator), new ReadSetting(new ResolveSettings(new SettingCatalog([new BusinessDefaultSettings()]), new InMemorySettings())));
    }
}
