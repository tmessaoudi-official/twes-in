<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Credit;

use Symfony\Component\Uid\Uuid;

/**
 * The customer whose credit balance is spent, held so that two uses of the balance at once never both fit it. A port
 * this module owns, answered by the customers', so this module never locks their row itself. It runs inside the
 * spending's transaction and holds until it ends.
 */
interface CustomerAccountsHeld
{
    /**
     * Holds the row of the customer kept for this one: the customer it was merged into, or itself, since the kept one
     * holds the credit of both. Nothing is held for a customer the company does not have.
     */
    public function hold(Uuid $companyId, Uuid $customerId): void;
}
