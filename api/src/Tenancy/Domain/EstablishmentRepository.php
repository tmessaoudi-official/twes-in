<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Symfony\Component\Uid\Uuid;

interface EstablishmentRepository
{
    /** @return list<Establishment> one company's establishments, the default first, then by code */
    public function ofCompany(Uuid $companyId): array;

    /** Null for an establishment that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?Establishment;

    public function ofCodeInCompany(string $code, Uuid $companyId): ?Establishment;

    public function save(Establishment $establishment): void;
}
