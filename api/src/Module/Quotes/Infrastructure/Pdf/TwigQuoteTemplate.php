<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\Pdf;

use App\Fiscal\Application\CurrencyScales;
use App\Module\Quotes\Application\QuotePage;
use App\Module\Quotes\Application\QuoteTemplate;
use App\Tenancy\Application\Company\CompanyLogo;
use Twig\Environment;

/** `templates/pdf/quote.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigQuoteTemplate implements QuoteTemplate
{
    public function __construct(private Environment $twig, private CurrencyScales $scales, private CompanyLogo $logo)
    {
    }

    public function html(QuotePage $page): string
    {
        $company = $page->quote->getCompany();

        return $this->twig->render('pdf/quote.html.twig', [
            'page' => $page,
            'quote' => $page->quote,
            'header' => $page->quote->getHeader(),
            'seller' => $page->seller,
            'customer' => $page->customer,
            'country' => strtolower($company->getCountryCode()),
            'scale' => $this->scales->of($page->seller->currency),
            'locale' => $page->language,
            'logo' => $this->logo->dataUri($company),
        ]);
    }
}
