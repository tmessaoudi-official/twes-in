<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\SectionSubtotal;
use App\Fiscal\Domain\Calculation\SectionSubtotals;
use App\Module\Invoices\Application\InvoicePage;
use App\Module\Invoices\Application\InvoiceTemplate;
use App\Module\Invoices\Domain\InvoiceLine;
use App\Tenancy\Application\Company\CompanyLogo;
use Twig\Environment;

/** `templates/pdf/invoice.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigInvoiceTemplate implements InvoiceTemplate
{
    public function __construct(private Environment $twig, private CurrencyScales $scales, private CompanyLogo $logo)
    {
    }

    public function html(InvoicePage $page): string
    {
        $company = $page->invoice->getCompany();
        // Each section by the line it opens on and the line it closes on, so the table prints its title above the one
        // and its subtotal below the other.
        $sections = SectionSubtotals::of(array_map(static fn (InvoiceLine $line, array $fixed): array => [$line->getSection(), $fixed['net']], $page->invoice->getLines(), $page->figures->lines));

        return $this->twig->render('pdf/invoice.html.twig', [
            'page' => $page,
            'invoice' => $page->invoice,
            'header' => $page->invoice->getHeader(),
            'figures' => $page->figures,
            'seller' => $page->seller,
            'customer' => $page->customer,
            'country' => strtolower($company->getCountryCode()),
            'scale' => $this->scales->of($page->seller->currency),
            'locale' => $page->language,
            'logo' => $this->logo->dataUri($company),
            'sectionStarts' => array_column(array_map(static fn (SectionSubtotal $section): array => ['at' => $section->firstLine, 'section' => $section], $sections), 'section', 'at'),
            'sectionEnds' => array_column(array_map(static fn (SectionSubtotal $section): array => ['at' => $section->lastLine(), 'section' => $section], $sections), 'section', 'at'),
        ]);
    }
}
