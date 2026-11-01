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

    /**
     * Where the rule refuses a sale beyond the stock, or asks it confirmed, the sale takes its stock out here, inside the
     * document's own transaction, once it has its number: under the stock's locks, the shortfall read again there, so two
     * sales of the last unit at the same moment cannot both pass, as they do on `shortfall`'s read alone. A refusal rolls
     * the number back with the rest. Where the rule allows any sale nothing is taken here: the stock moves once the
     * document is committed, and a move that fails then is told rather than refusing what was sold.
     *
     * @param list<SoldLine> $lines
     *
     * @throws ShortOfStock
     */
    public function takeOut(Company $company, Uuid $establishmentId, ShortSaleConsent $consent, SoldDocument $document, array $lines): void;
}
