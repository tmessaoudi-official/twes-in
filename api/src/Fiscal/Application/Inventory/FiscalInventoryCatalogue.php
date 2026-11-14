<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Fiscal\Application\Inventory;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Every context's part of the fiscal inventory, in one order, each member's code once. */
final readonly class FiscalInventoryCatalogue
{
    /** @var list<DeclaresFiscalRecords> */
    private array $declarations;

    /** @param iterable<DeclaresFiscalRecords> $declarations */
    public function __construct(#[AutowireIterator('app.fiscal.records')] iterable $declarations)
    {
        $this->declarations = array_values([...$declarations]);
        $seen = [];
        foreach ($this->records() as $records) {
            if (isset($seen[$records->code])) {
                throw new \LogicException(\sprintf('The fiscal inventory member %s is declared twice.', $records->code));
            }
            $seen[$records->code] = true;
        }
    }

    /** @return list<DeclaresFiscalRecords> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return list<FiscalRecords> */
    public function records(): array
    {
        $records = [];
        foreach ($this->declarations as $declaration) {
            $records = [...$records, ...$declaration->fiscalRecords()];
        }

        return $records;
    }

    /** @return list<FiscalRecords> the members counted per company */
    public function companyRecords(): array
    {
        return array_values(array_filter($this->records(), static fn (FiscalRecords $records): bool => null !== $records->companyColumn));
    }

    /** @return list<FiscalRecords> the members counted once for the platform */
    public function platformRecords(): array
    {
        return array_values(array_filter($this->records(), static fn (FiscalRecords $records): bool => null === $records->companyColumn));
    }
}
