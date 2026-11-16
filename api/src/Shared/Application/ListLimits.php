<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application;

/**
 * The most entries a list handed in may hold, the operator's to set (`app.list_limits`): a request's work grows with
 * its lists, and one document of ten thousand lines held a worker for fifteen seconds.
 */
interface ListLimits
{
    /** A document's lines: an invoice, a quote, a delivery note, an order to a vendor, an expense, a receipt. */
    public const string DOCUMENT_LINES = 'document.lines';

    /** A record's name in each other language its documents print in (a tax, a unit). */
    public const string RECORD_NAMES = 'record.names';

    /** A party's registration numbers (a customer, a vendor, the company's own profile). */
    public const string RECORD_IDENTIFIERS = 'record.identifiers';

    /** The taxes a record or a document names: a customer's or a product's defaults, a document's own, an expense's printed ones. */
    public const string RECORD_TAXES = 'record.taxes';

    /** A record's custom field values (a customer, a product). */
    public const string RECORD_CUSTOM_FIELDS = 'record.custom_fields';

    /** The vendors a product is bought from. */
    public const string PRODUCT_VENDORS = 'product.vendors';

    /** The places one product is kept in: their order, a count's or a receipt's quantities by place. */
    public const string PRODUCT_PLACES = 'product.places';

    /** Each kind of change one stock map step saves (rectangles drawn, moved or undrawn, places created, building pieces). */
    public const string STOCK_MAP_CHANGES = 'stock_map.changes';

    /** The permissions a role holds. */
    public const string ROLE_PERMISSIONS = 'role.permissions';

    /** At least 1. */
    public function most(string $list): int;
}
