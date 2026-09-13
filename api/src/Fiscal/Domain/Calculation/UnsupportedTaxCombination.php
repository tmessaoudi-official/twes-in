<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain\Calculation;

/**
 * Tax-inclusive entry on a line carrying a tax that enters the VAT base, or more than one percentage tax: no source
 * says how that compound extraction is rounded (ruling of 2026-09-13). Tax-exclusive entry works for all of them.
 */
final class UnsupportedTaxCombination extends InvalidDocument
{
}
