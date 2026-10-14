<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

use App\Module\Expenses\Domain\ExpenseDetails;
use Symfony\Component\Uid\Uuid;

/**
 * An expense as written: its details, its bill (lines of a net and the ids of the rates on it, the ids of its fixed
 * charges, the tax amounts it prints by code) and the ids of what it names, each looked up in the company.
 */
final readonly class ExpenseInput
{
    /**
     * @param list<array{amountNet: string, taxComponentIds: list<Uuid>}> $lines
     * @param list<Uuid>                                                  $documentTaxComponentIds
     * @param array<string, string>                                       $printedTaxes
     */
    public function __construct(
        public ExpenseDetails $details,
        public array $lines,
        public ?Uuid $vendorId = null,
        public ?Uuid $categoryId = null,
        public array $documentTaxComponentIds = [],
        public array $printedTaxes = [],
    ) {
    }

    /**
     * A receipt: one line at one rate or none.
     *
     * @return list<array{amountNet: string, taxComponentIds: list<Uuid>}>
     */
    public static function oneLine(string $amountNet, ?Uuid $taxComponentId = null): array
    {
        return [['amountNet' => $amountNet, 'taxComponentIds' => null === $taxComponentId ? [] : [$taxComponentId]]];
    }
}
