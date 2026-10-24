<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Texts\Domain\Repository;

use App\Tenancy\Domain\Model\Company;
use App\Texts\Domain\Model\TextOverride;
use App\Texts\Domain\ValueObject\TextAddress;

interface TextOverrideRepository
{
    /** The text at that address for the company, or for the installation when no company is named; its row held until the transaction this runs in ends. */
    public function lockedFind(?Company $company, TextAddress $address): ?TextOverride;

    /**
     * @return list<TextOverride> the installation's texts in that language (no company), or the company's own
     */
    public function of(?Company $company, string $language): array;

    /** A first text meeting another written meanwhile at the same address is refused as a save made since. */
    public function save(TextOverride $override): void;

    public function remove(TextOverride $override): void;
}
