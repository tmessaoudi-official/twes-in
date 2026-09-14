<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\Pdf;

use App\Fiscal\Application\CurrencyScales;
use App\Module\DeliveryNotes\Application\DeliveryNotePage;
use App\Module\DeliveryNotes\Application\DeliveryNoteTemplate;
use Twig\Environment;

/** `templates/pdf/delivery_note.html.twig`, worded from `translations/pdf.<language>.yaml`. */
final readonly class TwigDeliveryNoteTemplate implements DeliveryNoteTemplate
{
    public function __construct(private Environment $twig, private CurrencyScales $scales)
    {
    }

    public function html(DeliveryNotePage $page): string
    {
        $company = $page->note->getCompany();

        return $this->twig->render('pdf/delivery_note.html.twig', [
            'page' => $page,
            'note' => $page->note,
            'header' => $page->note->getHeader(),
            'company' => $company,
            'profile' => $company->getProfile(),
            'establishment' => $page->note->getEstablishment(),
            'customer' => $page->customer,
            'country' => strtolower($company->getCountryCode()),
            'scale' => $this->scales->of($company->getCurrency()),
            'locale' => $page->language,
        ]);
    }
}
