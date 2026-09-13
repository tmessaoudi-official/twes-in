<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Fiscal\Domain\TaxComponent;
use App\Fiscal\Domain\TaxComponentRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryTaxComponents implements TaxComponentRepository
{
    /** @var list<TaxComponent> */
    public array $components = [];

    public function ofCompany(Uuid $companyId): array
    {
        $mine = array_values(array_filter($this->components, static fn (TaxComponent $c) => $c->getCompany()->getId()->equals($companyId)));
        usort($mine, static fn (TaxComponent $a, TaxComponent $b) => [$a->getSortOrder(), $a->getCode()] <=> [$b->getSortOrder(), $b->getCode()]);

        return $mine;
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?TaxComponent
    {
        foreach ($this->ofCompany($companyId) as $component) {
            if ($component->getId()->equals($id)) {
                return $component;
            }
        }

        return null;
    }

    public function ofCodeInCompany(string $code, Uuid $companyId): ?TaxComponent
    {
        foreach ($this->ofCompany($companyId) as $component) {
            if ($component->getCode() === $code) {
                return $component;
            }
        }

        return null;
    }

    public function save(TaxComponent $component): void
    {
        if (!\in_array($component, $this->components, true)) {
            $this->components[] = $component;
        }
    }
}
