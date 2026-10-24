<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Infrastructure\Pdf;

use App\Fiscal\Application\CurrencyScales;
use App\Module\Purchases\Application\PurchaseOrderPage;
use App\Module\Purchases\Application\PurchaseOrderTemplate;
use App\Tenancy\Application\Company\CompanyLogo;
use App\Tenancy\Application\Company\DemoCompanies;
use App\Texts\Application\TextsInForce;
use Twig\Environment;

/** `templates/pdf/purchase_order.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigPurchaseOrderTemplate implements PurchaseOrderTemplate
{
    public function __construct(private Environment $twig, private TextsInForce $texts, private CurrencyScales $scales, private CompanyLogo $logo, private DemoCompanies $demos)
    {
    }

    public function html(PurchaseOrderPage $page): string
    {
        $company = $page->order->getCompany();

        // In the wording the company gives its printed labels, else the installation's.
        return $this->texts->for($company->getId(), fn (): string => $this->twig->render('pdf/purchase_order.html.twig', [
            'page' => $page,
            'order' => $page->order,
            'header' => $page->order->getHeader(),
            'vendor' => $page->vendor,
            'buyer' => $page->buyer,
            'country' => strtolower($company->getCountryCode()),
            // A figure prints every decimal it carries, never fewer than this: a price finer than the currency
            // (0.3333 a piece) is printed whole, so the vendor can check what each line comes to.
            'scale' => $this->scales->of($page->buyer->currency),
            'locale' => $page->language,
            'logo' => $this->logo->dataUri($company),
            'demo' => $this->demos->isDemo($company->getId()),
        ]));
    }
}
