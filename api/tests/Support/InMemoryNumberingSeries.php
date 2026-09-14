<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Domain\NumberingSeries;
use App\Tenancy\Domain\NumberingSeriesRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryNumberingSeries implements NumberingSeriesRepository
{
    /** @var list<NumberingSeries> */
    public array $series = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->series, static fn (NumberingSeries $s) => $s->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (NumberingSeries $a, NumberingSeries $b) => [$a->getEstablishment()->getCode(), $a->getDocumentType()] <=> [$b->getEstablishment()->getCode(), $b->getDocumentType()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?NumberingSeries
    {
        foreach ($this->ofCompany($companyId) as $series) {
            if ($series->getId()->equals($id)) {
                return $series;
            }
        }

        return null;
    }

    public function lockedDefaultFor(Uuid $establishmentId, string $documentType): ?NumberingSeries
    {
        foreach ($this->series as $series) {
            if ($series->isDefault() && $series->getDocumentType() === $documentType && $series->getEstablishment()->getId()->equals($establishmentId)) {
                return $series;
            }
        }

        return null;
    }

    public function save(NumberingSeries $series): void
    {
        if (!\in_array($series, $this->series, true)) {
            $this->series[] = $series;
        }
    }
}
