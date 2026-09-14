<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Fiscal\Application\CurrencyScales;
use App\Module\Invoices\Application\InvoicePage;
use App\Module\Invoices\Application\InvoiceTemplate;
use Twig\Environment;

/** `templates/pdf/invoice.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigInvoiceTemplate implements InvoiceTemplate
{
    public function __construct(private Environment $twig, private CurrencyScales $scales)
    {
    }

    public function html(InvoicePage $page): string
    {
        $company = $page->invoice->getCompany();

        return $this->twig->render('pdf/invoice.html.twig', [
            'page' => $page,
            'invoice' => $page->invoice,
            'header' => $page->invoice->getHeader(),
            'figures' => $page->figures,
            'company' => $company,
            'profile' => $company->getProfile(),
            'establishment' => $page->invoice->getEstablishment(),
            'customer' => $page->customer,
            'country' => strtolower($company->getCountryCode()),
            'scale' => $this->scales->of($company->getCurrency()),
            'locale' => $page->language,
        ]);
    }
}
