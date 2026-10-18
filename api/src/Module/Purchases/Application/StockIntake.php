<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Purchases\Application;

use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/**
 * The stock a receipt puts goods into. A port this module owns, answered by the inventory's, so neither calls into the
 * other. It is called inside the transaction that records the receipt, so goods are on the shelves exactly when the
 * order says they came: a line the stock refuses refuses the whole receipt.
 */
interface StockIntake
{
    /**
     * Puts each line's goods where it says, as receipts from the document's vendor. A product whose stock is not kept
     * moves nothing and is not refused: the order still records that it came.
     *
     * @param non-empty-list<IntakeLine> $lines in the order the receipt gives them
     *
     * @throws IntakeRefused naming the line, by its index, and the field
     */
    public function receive(Company $company, IntakeDocument $document, array $lines, ?IntakeCost $apply, ?Uuid $actorUserId): void;

    /**
     * Whether what a receipt's cost does to the products' cost is left to whoever receives: otherwise the company has
     * decided, and the `$apply` a receipt sends counts for nothing, so a screen does not ask it.
     */
    public function leavesCostToWhoReceives(Company $company): bool;
}
