<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Domain;

use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

interface ProductReferenceSequenceRepository
{
    /** The company's sequence as it stands, read without a lock; null until its first reference is generated. */
    public function of(Uuid $companyId): ?ProductReferenceSequence;

    /**
     * The company's sequence, locked until the transaction it is read in ends, and made the first time a company
     * generates a reference: two first saves at once still make one.
     */
    public function lockedFor(Company $company): ProductReferenceSequence;

    public function save(ProductReferenceSequence $sequence): void;
}
