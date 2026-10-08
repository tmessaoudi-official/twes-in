<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\AccountingExport\Infrastructure\Export;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\AccountingExport\Application\PurchaseEntries;
use App\Module\AccountingExport\Application\SalesDocuments;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportModule;
use App\Module\AccountingExport\Infrastructure\Module\AccountingExportPermission;
use App\Tenancy\Domain\Company;

/**
 * The VAT summary of a period: per tax on a rate, the base and the tax of the sales journal and of the purchases
 * journal, added up. It adds what the journals hold and says nothing of what is due, which is the accountant's to
 * declare: fixed charges, which have no base, are left to the sales journal.
 */
final readonly class VatSummaryExport implements DeclaresExport
{
    public function __construct(private SalesDocuments $sales, private PurchaseEntries $purchases, private CurrencyScales $scales)
    {
    }

    public function key(): string
    {
        return 'vat-summary';
    }

    public function permission(): string
    {
        return AccountingExportPermission::EXPORT;
    }

    public function module(): string
    {
        return AccountingExportModule::KEY;
    }

    public function columns(Company $company): array
    {
        return ['tax_code', 'tax_rate', 'sales_base', 'sales_tax', 'purchases_base', 'purchases_tax'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $period = PeriodAsked::of($query);
        $zero = Decimal::zero();
        /** @var array<string, array{0: string, 1: string, 2: \BcMath\Number, 3: \BcMath\Number, 4: \BcMath\Number, 5: \BcMath\Number}> $sums */
        $sums = [];
        foreach ($this->sales->issuedIn($company, $period) as $document) {
            foreach ($document->taxes as $tax) {
                if (null === $tax->rate || null === $tax->base) {
                    continue;
                }
                $key = $tax->code.'|'.$tax->rate;
                $sums[$key] ??= [$tax->code, $tax->rate, $zero, $zero, $zero, $zero];
                $sums[$key][2] = $sums[$key][2]->add(Decimal::of($tax->base));
                $sums[$key][3] = $sums[$key][3]->add(Decimal::of($tax->amount));
            }
        }
        foreach ($this->purchases->enteredIn($company, $period) as $entry) {
            if ('' === $entry->taxCode || null === $entry->taxRate) {
                continue;
            }
            $key = $entry->taxCode.'|'.$entry->taxRate;
            $sums[$key] ??= [$entry->taxCode, $entry->taxRate, $zero, $zero, $zero, $zero];
            $sums[$key][4] = $sums[$key][4]->add(Decimal::of($entry->net));
            $sums[$key][5] = $sums[$key][5]->add(Decimal::of($entry->tax));
        }
        ksort($sums);
        $scale = $this->scales->of($company->getCurrency());
        foreach ($sums as [$code, $rate, $salesBase, $salesTax, $purchasesBase, $purchasesTax]) {
            yield [$code, $rate, Decimal::format($salesBase, $scale), Decimal::format($salesTax, $scale), Decimal::format($purchasesBase, $scale), Decimal::format($purchasesTax, $scale)];
        }
    }
}
