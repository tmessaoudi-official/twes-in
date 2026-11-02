<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Expenses\Infrastructure\DataHealth;

use App\DataHealth\Application\DataFinding;
use App\DataHealth\Application\DeclaresDataChecks;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * What an expense's stored totals must still say: worked out from its lines and taxes when it was written, its net is
 * its lines', its tax the rates' charged amounts, its fixed charges the rest of its taxes, and its gross the three; a
 * withholding has its rate and its amount, or neither.
 */
final readonly class ExpenseDataChecks implements DeclaresDataChecks
{
    public const string ARITHMETIC = 'expenses.arithmetic';

    public function __construct(private Connection $connection)
    {
    }

    public function dataChecks(): array
    {
        return [self::ARITHMETIC];
    }

    public function findings(string $check): iterable
    {
        if (self::ARITHMETIC !== $check) {
            throw new \LogicException(\sprintf('No expense data check is named %s.', $check));
        }
        $rows = $this->connection->iterateAssociative(<<<'SQL'
            SELECT company_id, id, unequal FROM (
                SELECT e.company_id, e.id, array_remove(ARRAY[
                    CASE WHEN e.amount_net IS DISTINCT FROM COALESCE((SELECT SUM(l.amount_net) FROM expense_line l WHERE l.expense_id = e.id), 0) THEN 'net' END,
                    CASE WHEN e.tax_amount IS DISTINCT FROM COALESCE((SELECT SUM(t.amount) FROM expense_tax t WHERE t.expense_id = e.id AND t.rate IS NOT NULL), 0) THEN 'tax' END,
                    CASE WHEN e.fixed_amount IS DISTINCT FROM COALESCE((SELECT SUM(t.amount) FROM expense_tax t WHERE t.expense_id = e.id AND t.rate IS NULL), 0) THEN 'fixed' END,
                    CASE WHEN e.amount_gross IS DISTINCT FROM e.amount_net + e.tax_amount + e.fixed_amount THEN 'gross' END,
                    CASE WHEN (e.withholding_rate IS NULL) <> (e.withholding_amount IS NULL) THEN 'withholding' END
                ], NULL) AS unequal
                FROM expense e
            ) checked
            WHERE cardinality(unequal) > 0
            ORDER BY company_id, id
            SQL);
        foreach ($rows as $row) {
            yield new DataFinding(self::ARITHMETIC, Uuid::fromString(Rows::text($row['company_id'])), \sprintf('expense %s does not add up: %s', Rows::text($row['id']), Rows::text($row['unequal'])));
        }
    }
}
