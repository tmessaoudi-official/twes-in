<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Export;

use App\Fiscal\Application\CurrencyScales;
use App\Fiscal\Domain\Calculation\Decimal;
use App\ImportExport\Application\DeclaresExport;
use App\ImportExport\Application\ExportQuery;
use App\Module\Expenses\Application\ManageExpenses;
use App\Module\Expenses\Domain\Expense;
use App\Module\Expenses\Domain\ExpenseSearch;
use App\Module\Expenses\Infrastructure\ApiPlatform\ExpensePermission;
use App\Module\Expenses\Infrastructure\ApiPlatform\ExpenseSearchReader;
use App\Module\Expenses\Infrastructure\Module\ExpensesModule;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;

/**
 * The expenses list as a file (docs/SPEC.md § 7, row 60): one row per expense, under the search, status, vendor,
 * category and order the screen shows. Days go out as 2026-09-15 and amounts at the currency's scale, as the list
 * shows them, so a spreadsheet sums and sorts them without guessing a locale. `amount_to_pay` is what the supplier is
 * handed, the gross less what was withheld, so it is the same on a draft as on a paid expense: whether it was paid is
 * `status` and `paid_on`.
 */
final readonly class ExpenseExport implements DeclaresExport
{
    private const int BATCH = 200;

    public function __construct(private ManageExpenses $manage, private CurrencyScales $scales)
    {
    }

    public function key(): string
    {
        return 'expenses';
    }

    public function permission(): string
    {
        return ExpensePermission::READ;
    }

    public function module(): string
    {
        return ExpensesModule::KEY;
    }

    public function columns(Company $company): array
    {
        return ['date', 'reference', 'description', 'vendor', 'category', 'currency', 'amount_net', 'tax_amount', 'amount_gross', 'withholding_amount', 'amount_to_pay', 'status', 'due_date', 'paid_on', 'payment_method', 'notes'];
    }

    public function rows(Company $company, ExportQuery $query): iterable
    {
        $search = ExpenseSearchReader::read($query->parameters(), $query->text(), $query->order(ExpenseSearch::SORTS));
        $scale = $this->scales->of($company->getCurrency());

        for ($page = 1;; ++$page) {
            $answer = $this->manage->search($company, $search, new PageRequest($page, self::BATCH));
            foreach ($answer->items as $expense) {
                yield self::row($expense, $scale);
            }
            if ($page * self::BATCH >= $answer->total || [] === $answer->items) {
                return;
            }
        }
    }

    /** @return list<string> */
    private static function row(Expense $expense, int $scale): array
    {
        $amount = static fn (string $stored): string => Decimal::format(Decimal::of($stored), $scale);

        return [
            $expense->getDate()->format('Y-m-d'),
            $expense->getReference() ?? '',
            $expense->getDescription(),
            $expense->getVendor()?->getProfile()->name ?? $expense->getPayee() ?? '',
            $expense->getCategory()?->getName() ?? '',
            $expense->getCurrency(),
            $amount($expense->getAmountNet()),
            $amount($expense->getTaxAmount()),
            $amount($expense->getAmountGross()),
            null === $expense->getWithholdingAmount() ? '' : $amount($expense->getWithholdingAmount()),
            $amount($expense->getAmountPaid()),
            $expense->getStatus()->value,
            $expense->getDueDate()?->format('Y-m-d') ?? '',
            $expense->getPaidOn()?->format('Y-m-d') ?? '',
            $expense->getPaymentMethod()->value ?? '',
            $expense->getNotes() ?? '',
        ];
    }
}
