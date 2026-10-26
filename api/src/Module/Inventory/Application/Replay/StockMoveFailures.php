<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Application\Replay;

use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Domain\Repository\StockMoveFailureRepository;
use App\Module\Inventory\Domain\ValueObject\FailedMove;
use App\Shared\Application\LiveChange;
use App\Shared\Application\LiveChanges;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A document's stock move that failed after the document was kept (workflow hunt, CON-1), recorded where it failed: the
 * listener of the validation, cancellation or issue. It is listed on « À surveiller » and on the document's page and
 * settled there (`SettleStockMoveFailures`); recording it is said as a change of the stock, which those screens hear.
 */
final readonly class StockMoveFailures
{
    public const string KIND = 'stock';
    public const string FAILED = 'stock.move_failed';
    public const string REPLAYED = 'stock.move_replayed';
    public const string SETTLED = 'stock.move_settled';

    public function __construct(
        private StockMoveFailureRepository $failures,
        private CompanyRepository $companies,
        private LiveChanges $changes,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** Where the move failed; failing again keeps the first record. */
    public function record(Uuid $companyId, FailedMove $move, Uuid $documentId, string $number): void
    {
        $this->transactions->run(function () use ($companyId, $move, $documentId, $number): void {
            $company = $this->companies->ofId($companyId);
            if (null === $company || null !== $this->failures->of($companyId, $move, $documentId)) {
                return;
            }
            $this->failures->save(new StockMoveFailure($company, $move, $documentId, $number, $this->clock->now()));
            $this->changes->stage(new LiveChange(self::KIND, $documentId, self::FAILED, null, $companyId));
        });
    }
}
