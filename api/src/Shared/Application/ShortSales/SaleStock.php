<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Application\ShortSales;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * What a sale would take short at an establishment, as the stock module reads it: its rule for the seller's role there,
 * and, unless it allows any sale, each product the document's lines take more of than its places hold. Asked inside the
 * document's own transaction, before it takes a number. A company without the stock module answers that it allows.
 */
interface SaleStock
{
    /** @param list<SoldLine> $lines */
    public function shortfall(Company $company, Uuid $establishmentId, ?Uuid $roleId, array $lines): Shortfall;
}
