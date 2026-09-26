<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\ModuleRegistry\Application;

/**
 * The modules of the complete product that are not built yet (docs/SPEC.md § 7, 2026-09-26 10:08, row 150): listed
 * beside the real ones with « Bientôt », never on. When one ships, its entry leaves this list for its own
 * `DeclaresModule` in `src/Module/<Name>/`; the catalogue refuses the key declared in both.
 */
final readonly class PlannedModules
{
    /** @var list<ModuleManifest> */
    private array $manifests;

    /** @param list<ModuleManifest>|null $manifests the shipped list when none is given */
    public function __construct(?array $manifests = null)
    {
        $this->manifests = $manifests ?? [
            // Selling.
            new ModuleManifest('quotes', 'modules.quotes', ['customers', 'invoices'], planned: 'v1'),
            new ModuleManifest('works', 'modules.works', ['invoices', 'quotes'], planned: 'v1'),
            new ModuleManifest('price_lists', 'modules.price_lists', ['customers', 'products'], planned: 'v1'),
            new ModuleManifest('register', 'modules.register', ['invoices', 'products'], planned: 'v1'),
            new ModuleManifest('recurring', 'modules.recurring', ['invoices'], planned: 'v1'),
            new ModuleManifest('statements', 'modules.statements', ['customers', 'invoices'], planned: 'v1'),
            new ModuleManifest('mailing', 'modules.mailing', ['invoices'], planned: 'later'),
            new ModuleManifest('whatsapp', 'modules.whatsapp', ['invoices'], planned: 'v1'),
            new ModuleManifest('portal', 'modules.portal', ['customers', 'invoices'], planned: 'later'),
            // Buying and stock.
            new ModuleManifest('purchases', 'modules.purchases', ['inventory', 'products', 'vendors'], planned: 'v1'),
            new ModuleManifest('stock_valuation', 'modules.stock_valuation', ['inventory'], planned: 'v1'),
            new ModuleManifest('composites', 'modules.composites', ['products'], planned: 'later'),
            // Money and compliance.
            new ModuleManifest('reports', 'modules.reports', [], planned: 'v1'),
            new ModuleManifest('declarations', 'modules.declarations', ['invoices'], planned: 'v1'),
            new ModuleManifest('accounting_export', 'modules.accounting_export', ['invoices'], planned: 'v1'),
            new ModuleManifest('einvoicing', 'modules.einvoicing', ['invoices'], planned: 'v1'),
            new ModuleManifest('currencies', 'modules.currencies', ['invoices'], planned: 'later'),
            new ModuleManifest('zakat', 'modules.zakat', [], planned: 'later'),
            // Café and restaurant.
            new ModuleManifest('venue', 'modules.venue', ['register'], planned: 'later'),
            new ModuleManifest('menu', 'modules.menu', ['products'], planned: 'later'),
            new ModuleManifest('service', 'modules.service', ['menu', 'register', 'venue'], planned: 'later'),
            new ModuleManifest('guests', 'modules.guests', ['customers'], planned: 'later'),
            new ModuleManifest('ratings', 'modules.ratings', ['venue'], planned: 'later'),
        ];
    }

    /** @return list<ModuleManifest> */
    public function manifests(): array
    {
        return $this->manifests;
    }
}
