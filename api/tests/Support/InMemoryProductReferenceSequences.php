<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Products\Domain\ProductReferenceSequence;
use App\Module\Products\Domain\ProductReferenceSequenceRepository;
use App\Tenancy\Domain\Company;
use Symfony\Component\Uid\Uuid;

final class InMemoryProductReferenceSequences implements ProductReferenceSequenceRepository
{
    /** @var array<string, ProductReferenceSequence> by company id */
    private array $sequences = [];
    /** @var array<string, int> the next number each company's saved sequence holds */
    private array $saved = [];

    public function of(Uuid $companyId): ?ProductReferenceSequence
    {
        $sequence = $this->sequences[$companyId->toRfc4122()] ?? null;

        // What a reader sees is what was saved, as a database would answer it.
        return null === $sequence || !isset($this->saved[$companyId->toRfc4122()]) ? null : $this->copyAt($sequence, $this->saved[$companyId->toRfc4122()]);
    }

    public function lockedFor(Company $company): ProductReferenceSequence
    {
        return $this->sequences[$company->getId()->toRfc4122()] ??= new ProductReferenceSequence($company);
    }

    public function save(ProductReferenceSequence $sequence): void
    {
        $key = $sequence->getCompany()->getId()->toRfc4122();
        $this->sequences[$key] = $sequence;
        $this->saved[$key] = $sequence->nextNumber();
    }

    private function copyAt(ProductReferenceSequence $sequence, int $next): ProductReferenceSequence
    {
        $copy = new ProductReferenceSequence($sequence->getCompany());
        while ($copy->nextNumber() < $next) {
            $copy->take();
        }

        return $copy;
    }
}
