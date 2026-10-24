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
use App\Tenancy\Application\Company\DemoCompanies;
use App\Texts\Application\TextsInForce;
use Twig\Environment;

/** `templates/pdf/quote.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigQuoteTemplate implements QuoteTemplate
{
    public function __construct(private Environment $twig, private TextsInForce $texts, private CurrencyScales $scales, private CompanyLogo $logo, private DemoCompanies $demos)
    {
    }

    public function html(QuotePage $page): string
    {
        $company = $page->quote->getCompany();

        // In the wording the company gives its printed labels, else the installation's.
        return $this->texts->for($company->getId(), fn (): string => $this->twig->render('pdf/quote.html.twig', [
            'page' => $page,
            'quote' => $page->quote,
            'header' => $page->quote->getHeader(),
            'seller' => $page->seller,
            'customer' => $page->customer,
            'country' => strtolower($company->getCountryCode()),
            'scale' => $this->scales->of($page->seller->currency),
            'locale' => $page->language,
            'logo' => $this->logo->dataUri($company),
            'demo' => $this->demos->isDemo($company->getId()),
        ]));
    }
}
