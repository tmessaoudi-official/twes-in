<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Domain\CustomerTaxRegime;
use App\Fiscal\Domain\CustomerTaxRegimeRepository;

final class InMemoryCustomerTaxRegimes implements CustomerTaxRegimeRepository
{
    /** @var list<CustomerTaxRegime> */
    public array $regimes = [];

    public function ofPreset(string $fiscalPreset): array
    {
        $mine = array_values(array_filter($this->regimes, static fn (CustomerTaxRegime $r) => $r->getFiscalPreset() === $fiscalPreset));
        usort($mine, static fn (CustomerTaxRegime $a, CustomerTaxRegime $b) => [$a->getSortOrder(), $a->getCode()] <=> [$b->getSortOrder(), $b->getCode()]);

        return $mine;
    }

    public function ofPresetAndCode(string $fiscalPreset, string $code): ?CustomerTaxRegime
    {
        foreach ($this->ofPreset($fiscalPreset) as $regime) {
            if ($regime->getCode() === $code) {
                return $regime;
            }
        }

        return null;
    }

    public function save(CustomerTaxRegime $regime): void
    {
        if (!\in_array($regime, $this->regimes, true)) {
            $this->regimes[] = $regime;
        }
    }
}
