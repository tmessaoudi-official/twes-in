<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Symfony\Component\Uid\Uuid;

interface NumberingSeriesRepository
{
    /** @return list<NumberingSeries> one company's series, by establishment code, then document type */
    public function ofCompany(Uuid $companyId): array;

    /** Null for a series that does not exist or belongs to another company. */
    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?NumberingSeries;

    /**
     * The default series an establishment numbers a document type from, read fresh and locked until the current
     * transaction ends, so a second one waits for it; null when the establishment numbers no such document.
     */
    public function lockedDefaultFor(Uuid $establishmentId, string $documentType): ?NumberingSeries;

    public function save(NumberingSeries $series): void;
}
