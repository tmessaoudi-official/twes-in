<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Module\Invoices\Application\Statement\StatementPage;
use App\Module\Invoices\Application\Statement\StatementTemplate;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Application\Company\DemoCompanies;
use App\Texts\Application\TextsInForce;
use Twig\Environment;

/** `templates/pdf/statement.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigStatementTemplate implements StatementTemplate
{
    public function __construct(private Environment $twig, private TextsInForce $texts, private CompanyLogo $logo, private DemoCompanies $demos)
    {
    }

    public function html(StatementPage $page): string
    {
        $company = $page->company;

        // In the wording the company gives its printed labels, else the installation's.
        return $this->texts->for($company->getId(), fn (): string => $this->twig->render('pdf/statement.html.twig', [
            'page' => $page,
            'statement' => $page->statement,
            'seller' => $page->seller,
            'customer' => $page->customer,
            'country' => strtolower($company->getCountryCode()),
            'locale' => $page->language,
            'logo' => $this->logo->dataUri($company),
            'demo' => $this->demos->isDemo($company->getId()),
        ]));
    }
}
