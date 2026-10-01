<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\Pdf;

use App\Module\Invoices\Application\StatementPage;
use App\Module\Invoices\Application\StatementTemplate;
use App\Tenancy\Application\Company\CompanyLogo;
use Twig\Environment;

/** `templates/pdf/statement.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigStatementTemplate implements StatementTemplate
{
    public function __construct(private Environment $twig, private CompanyLogo $logo)
    {
    }

    public function html(StatementPage $page): string
    {
        $company = $page->company;

        return $this->twig->render('pdf/statement.html.twig', [
            'page' => $page,
            'statement' => $page->statement,
            'seller' => $page->seller,
            'customer' => $page->customer,
            'country' => strtolower($company->getCountryCode()),
            'locale' => $page->language,
            'logo' => $this->logo->dataUri($company),
        ]);
    }
}
