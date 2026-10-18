<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Domain\Repository;

use App\Module\Purchases\Domain\Model\PurchaseOrder;
use App\Module\Purchases\Domain\ValueObject\PurchaseOrderSearch;
use App\Shared\Domain\ValueObject\Page;
use App\Shared\Domain\ValueObject\PageRequest;
use Symfony\Component\Uid\Uuid;

interface PurchaseOrderRepository
{
    /** @return Page<PurchaseOrder> the company's orders narrowed and sorted as the search asks, one page of them */
    public function search(Uuid $companyId, PurchaseOrderSearch $search, PageRequest $page): Page;

    /** @return array{all: int, statuses: array<string, int>} how many orders the search finds whatever their status, and by status */
    public function statusCounts(Uuid $companyId, PurchaseOrderSearch $search): array;

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?PurchaseOrder;

    /** Held until the transaction ends and read as it stands: a status check on it cannot be overtaken by another request. */
    public function lockedOfIdInCompany(Uuid $id, Uuid $companyId): ?PurchaseOrder;

    public function numberTaken(Uuid $companyId, string $number): bool;

    public function save(PurchaseOrder $order): void;
}
