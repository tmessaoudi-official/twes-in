<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

/**
 * What a tax component is, as opposed to how it is computed: a customer's tax regime excludes families (an export
 * sale carries no VAT, whatever the company named its VAT rates), and each family has exactly one kind.
 */
enum TaxFamily: string
{
    case Vat = 'vat';
    case Levy = 'levy';
    case Stamp = 'stamp';
    case Withholding = 'withholding';

    public function kind(): TaxKind
    {
        return match ($this) {
            self::Vat, self::Levy => TaxKind::PercentageLine,
            self::Stamp => TaxKind::FixedDocument,
            self::Withholding => TaxKind::WithholdingTotal,
        };
    }

    /**
     * Only a levy is added to the base of the VAT computed beside it: the Tunisian VAT base is the price "tous
     * frais, droits et taxes inclus … à l'exclusion de la TVA" (Code de la TVA art. 6-I, docs/fiscal/TN.md § 2).
     */
    public function mayEnterVatBase(): bool
    {
        return self::Levy === $this;
    }
}
