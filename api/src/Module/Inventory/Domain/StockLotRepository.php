<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Domain;

use Symfony\Component\Uid\Uuid;

interface StockLotRepository
{
    public function save(StockLot $lot): void;

    public function ofIdInCompany(Uuid $id, Uuid $companyId): ?StockLot;

    /** The product's lot under that code, exactly as written. */
    public function ofCode(Uuid $productId, string $code): ?StockLot;

    /**
     * Holds a lot of a product until the current transaction ends, by its code, so the lot need not exist yet: two
     * receipts of one new serial number at two sites then run one after the other, and the second finds the first's
     * piece in stock instead of both finding none. Taken inside a transaction only, before the stock is locked.
     */
    public function lock(Uuid $productId, string $code): void;
}
