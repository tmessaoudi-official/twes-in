<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Domain\Repository\StockMoveFailureRepository;
use App\Module\Inventory\Domain\ValueObject\FailedMove;
use Symfony\Component\Uid\Uuid;

final class InMemoryStockMoveFailures implements StockMoveFailureRepository
{
    /** @var list<StockMoveFailure> */
    public array $failures = [];

    public function ofId(Uuid $id, Uuid $companyId): ?StockMoveFailure
    {
        foreach ($this->failures as $failure) {
            if ($failure->getId()->equals($id) && $failure->getCompany()->getId()->equals($companyId)) {
                return $failure;
            }
        }

        return null;
    }

    public function of(Uuid $companyId, FailedMove $move, Uuid $documentId): ?StockMoveFailure
    {
        foreach ($this->failures as $failure) {
            if ($failure->getCompany()->getId()->equals($companyId) && $move === $failure->getMove() && $failure->getDocumentId()->equals($documentId)) {
                return $failure;
            }
        }

        return null;
    }

    public function ofDocument(Uuid $companyId, Uuid $documentId): array
    {
        return array_values(array_filter(
            $this->failures,
            static fn (StockMoveFailure $failure): bool => $failure->getCompany()->getId()->equals($companyId) && $failure->getDocumentId()->equals($documentId),
        ));
    }

    public function save(StockMoveFailure $failure): void
    {
        if (!\in_array($failure, $this->failures, true)) {
            $this->failures[] = $failure;
        }
    }

    public function remove(StockMoveFailure $failure): void
    {
        $this->failures = array_values(array_filter($this->failures, static fn (StockMoveFailure $kept): bool => $kept !== $failure));
    }
}
