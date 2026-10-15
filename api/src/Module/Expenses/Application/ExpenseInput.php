<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Application;

use App\Fiscal\Domain\Calculation\Decimal;
use App\Fiscal\Domain\TaxKind;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseDetails;
use App\Module\Expenses\Domain\ExpenseLine;
use App\Module\Expenses\Domain\ExpenseLineTax;
use App\Module\Expenses\Domain\ExpenseTax;
use App\Module\Expenses\Domain\InvalidExpense;
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
     * A model expense written again for another day: what was bought, from whom, under which category, its lines with
     * their rates, its fixed charges, the tax amounts its bill printed and its notes. The vendor's own reference, the
     * files and the payment belong to the bill that came, so the copy has none. Each id is looked up again when the
     * copy is made, so a rate, a category or a vendor that may no longer be named refuses it.
     *
     * @param int $currencyScale the company currency's decimals, which the amounts are written in
     *
     * @throws InvalidExpense
     */
    public static function copyOf(Expense $model, \DateTimeImmutable $day, int $currencyScale): self
    {
        $amount = static fn (string $stored): string => Decimal::format(Decimal::of($stored), $currencyScale);
        $taxes = $model->getTaxes();
        $printed = [];
        foreach ($taxes as $tax) {
            if ($tax->isPrinted()) {
                $printed[$tax->getCode()] = $amount($tax->getAmount());
            }
        }

        return new self(
            new ExpenseDetails($day, $model->getDescription(), null, $model->getNotes(), $model->getPayee()),
            array_map(static fn (ExpenseLine $line): array => [
                'amountNet' => $amount($line->getAmountNet()),
                'taxComponentIds' => array_map(static fn (ExpenseLineTax $tax): Uuid => $tax->getTaxComponent()->getId(), $line->getTaxes()),
            ], $model->getLines()),
            $model->getVendor()?->getId(),
            $model->getCategory()?->getId(),
            array_values(array_map(static fn (ExpenseTax $tax): Uuid => $tax->getTaxComponent()->getId(), array_filter($taxes, static fn (ExpenseTax $tax): bool => TaxKind::FixedDocument === $tax->getKind()))),
            $printed,
        );
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
