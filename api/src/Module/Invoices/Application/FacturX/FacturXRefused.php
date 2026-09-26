<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\FacturX;

/**
 * A document that is not written as Factur-X: not issued yet (`not_issued`), of a country the product writes no Factur-X
 * for (`preset_not_supported`), or lacking what EN 16931 asks for (`incomplete_document`, each gap named by its own code
 * and parameters for a screen to translate). A file a validator would refuse is never written instead.
 */
final class FacturXRefused extends \DomainException
{
    public const string NOT_ISSUED = 'not_issued';

    /** Every reason a document is refused for, as the contract lists them. */
    public const array REASONS = [self::NOT_ISSUED, 'preset_not_supported', 'incomplete_document'];

    /** Every gap an incomplete document is refused with, as the contract lists them. */
    public const array GAPS = [
        'seller_siren_missing', 'seller_vat_number_missing', 'seller_address_incomplete',
        'buyer_address_incomplete', 'buyer_vat_number_missing',
        'line_tax_unsupported', 'line_vat_ambiguous', 'vat_category_unknown', 'vat_exemption_undeclared',
        'document_tax_unsupported', 'vat_rate_shared', 'vat_rounding_differs',
    ];

    /**
     * @param array<string, string|int>                                                 $params
     * @param list<array{code: string, params: array<string, string|int|list<string>>}> $gaps
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $params = [],
        public readonly array $gaps = [],
    ) {
        parent::__construct($message);
    }
}
