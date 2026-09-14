<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Invoices\Application\InvoicePage;
use App\Module\Invoices\Application\InvoiceTemplate;

/** Keeps every page it was asked to lay out and answers a line naming it. */
final class RecordingInvoiceTemplate implements InvoiceTemplate
{
    /** @var list<InvoicePage> */
    public array $pages = [];

    public function html(InvoicePage $page): string
    {
        $this->pages[] = $page;

        return \sprintf('<p>%s %s</p>', $page->invoice->getNumber() ?? 'draft', $page->watermark ?? 'clean');
    }
}
