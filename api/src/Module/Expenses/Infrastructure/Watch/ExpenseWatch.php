<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\Watch;

use App\Module\Expenses\Infrastructure\ApiPlatform\Security\ExpensePermission;
use App\Module\Expenses\Infrastructure\Module\ExpensesModule;
use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use App\Tenancy\Domain\Company;
use App\Watch\Application\DeclaresWatch;
use App\Watch\Application\WatchItem;
use Doctrine\DBAL\Connection;

/**
 * The drafts whose amount is to be checked against the bill (« montant à vérifier »), as a recurring expense of variable
 * amount drafts them, until each is recorded. Oldest first: the bill of the oldest has been waiting longest. Only a
 * draft is ever flagged, since recording it clears the flag.
 */
final readonly class ExpenseWatch implements DeclaresWatch
{
    public const string AMOUNT_TO_CHECK = 'expenses.amount_to_check';

    private const string ROWS = 'SELECT e.id, e.description, COALESCE(v.name, e.payee) AS payee, e.amount_gross, e.currency, e.expense_date
          FROM expense e
          LEFT JOIN vendor v ON v.id = e.vendor_id
         WHERE e.company_id = :company AND e.amount_to_check';

    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'expenses';
    }

    public function module(): string
    {
        return ExpensesModule::KEY;
    }

    public function permission(): string
    {
        return ExpensePermission::READ;
    }

    public function kinds(): array
    {
        return [self::AMOUNT_TO_CHECK];
    }

    public function count(string $kind, Company $company, \DateTimeImmutable $today): int
    {
        self::known($kind);

        return (int) self::text($this->connection->fetchOne('SELECT COUNT(*) FROM expense e WHERE e.company_id = :company AND e.amount_to_check', ['company' => $company->getId()->toRfc4122()]));
    }

    public function page(string $kind, Company $company, \DateTimeImmutable $today, PageRequest $request): Page
    {
        $total = $this->count($kind, $company, $today);
        if (0 === $total) {
            return new Page([], 0, $request);
        }
        $rows = $this->connection->fetchAllAssociative(
            self::ROWS.' ORDER BY e.expense_date, e.id LIMIT :limit OFFSET :offset',
            ['company' => $company->getId()->toRfc4122(), 'limit' => $request->size, 'offset' => $request->offset()],
        );

        return new Page(array_map(static fn (array $row): WatchItem => new WatchItem(self::AMOUNT_TO_CHECK, self::text($row['id']), [
            'description' => self::text($row['description']),
            'payee' => \is_scalar($row['payee']) ? (string) $row['payee'] : '',
            'amount' => self::text($row['amount_gross']),
            'currency' => self::text($row['currency']),
            'date' => self::text($row['expense_date']),
        ]), $rows), $total, $request);
    }

    private static function known(string $kind): void
    {
        if (self::AMOUNT_TO_CHECK !== $kind) {
            throw new \LogicException(\sprintf('The expenses watch has no %s.', $kind));
        }
    }

    private static function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : throw new \LogicException('A column the query names came back empty.');
    }
}
