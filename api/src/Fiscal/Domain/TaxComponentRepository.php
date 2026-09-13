<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Domain;

use Symfony\Component\Uid\Uuid;

interface TaxComponentRepository
{
    /** @return list<TaxComponent> one company's components, by sort order then code */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a component that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?TaxComponent;

    public function ofCodeInCompany(string $code, Uuid $companyId): ?TaxComponent;

    public function save(TaxComponent $component): void;
}
