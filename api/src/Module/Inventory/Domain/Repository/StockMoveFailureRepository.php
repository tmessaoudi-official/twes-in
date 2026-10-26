<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain\Repository;

use App\Module\Inventory\Domain\Model\StockMoveFailure;
use App\Module\Inventory\Domain\ValueObject\FailedMove;
use Symfony\Component\Uid\Uuid;

interface StockMoveFailureRepository
{
    public function ofId(Uuid $id, Uuid $companyId): ?StockMoveFailure;

    public function of(Uuid $companyId, FailedMove $move, Uuid $documentId): ?StockMoveFailure;

    /** @return list<StockMoveFailure> the failed moves of one document, oldest first */
    public function ofDocument(Uuid $companyId, Uuid $documentId): array;

    public function save(StockMoveFailure $failure): void;

    public function remove(StockMoveFailure $failure): void;
}
