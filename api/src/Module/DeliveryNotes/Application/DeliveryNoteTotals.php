<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Application;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Domain\Calculation\DocumentCalculator;
use App\Fiscal\Domain\Calculation\DocumentInput;
use App\Fiscal\Domain\Calculation\DocumentTotals;
use App\Fiscal\Domain\Calculation\InvalidDocument;
use App\Fiscal\Domain\Calculation\LineInput;
use App\Fiscal\Domain\Calculation\Rate;
use App\Fiscal\Domain\Calculation\TaxBasis;
use App\Fiscal\Domain\Calculation\TaxInput;
use App\Fiscal\Domain\Calculation\UnsupportedTaxCombination;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLine;
use App\Module\DeliveryNotes\Domain\DeliveryNoteLineTax;

/**
 * What a delivery note's lines come to, worked out by the calculator every document shares: prices net of tax, the
 * line taxes at the rates the note keeps, rounded where the company's fiscal preset rounds VAT and at its currency's
 * scale. A delivery note carries no discount and no document tax.
 */
final readonly class DeliveryNoteTotals
{
    public function __construct(private FiscalPresets $presets, private CurrencyScales $scales)
    {
    }

    /**
     * @throws InvalidDocument           when the lines cannot be totalled
     * @throws UnsupportedTaxCombination when the lines combine taxes the calculator does not
     */
    public function of(DeliveryNote $note): DocumentTotals
    {
        $company = $note->getCompany();
        $lines = array_map(static fn (DeliveryNoteLine $line): LineInput => new LineInput(
            $line->getQuantity(),
            $line->getUnitPriceNet(),
            null,
            array_map(static fn (DeliveryNoteLineTax $tax): TaxInput => TaxInput::percentage($tax->getCode(), Rate::fromPercentage($tax->getRate()), $tax->entersVatBase()), $line->getTaxes()),
        ), $note->getLines());

        return new DocumentCalculator()->calculate(new DocumentInput(
            $this->scales->of($company->getCurrency()),
            false,
            TaxBasis::Exclusive,
            $this->presets->get($company->getFiscalPreset())->vatRoundingPoint,
            $lines,
        ));
    }
}
