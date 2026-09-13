<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

interface CustomerTaxRegimeRepository
{
    /** @return list<CustomerTaxRegime> one preset's regimes, by sort order */
    public function ofPreset(string $fiscalPreset): array;

    public function ofPresetAndCode(string $fiscalPreset, string $code): ?CustomerTaxRegime;

    public function save(CustomerTaxRegime $regime): void;
}
