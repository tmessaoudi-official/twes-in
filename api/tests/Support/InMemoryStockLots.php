<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Inventory\Domain\StockLot;
use App\Module\Inventory\Domain\StockLotRepository;
use App\Shared\Application\Transactions;
use Symfony\Component\Uid\Uuid;

final class InMemoryStockLots implements StockLotRepository
{
    /** @var list<StockLot> */
    public array $lots = [];

    /** @var list<string> "lock-lot <product> <code>", with " in transaction" while one is open */
    public array $calls = [];

    public ?Transactions $transactions = null;

    public function save(StockLot $lot): void
    {
        if (!\in_array($lot, $this->lots, true)) {
            $this->lots[] = $lot;
        }
    }

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockLot
    {
        foreach ($this->lots as $lot) {
            if ($lot->getId()->equals($id) && $lot->getCompany()->getId()->equals($companyId)) {
                return $lot;
            }
        }

        return null;
    }

    public function ofCode(Uuid $productId, string $code): ?StockLot
    {
        foreach ($this->lots as $lot) {
            if ($lot->getProduct()->getId()->equals($productId) && $lot->getCode() === $code) {
                return $lot;
            }
        }

        return null;
    }

    public function lock(Uuid $productId, string $code): void
    {
        $this->calls[] = \sprintf('lock-lot %s %s%s', $productId->toRfc4122(), $code, true === $this->transactions?->active() ? ' in transaction' : '');
    }
}
