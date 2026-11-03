<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Credit;

use Symfony\Component\Uid\Uuid;

/**
 * A customer's account held while an issue weighs it against the credit limit, so that two invoices of the customer
 * issued at once, in two series no number's lock lines up, read one after the other what was owed before them. It runs
 * inside the issuing transaction, last of its locks, and holds until it ends. It is not the customer's row, which the
 * customers' own writes and the spending of a credit balance (`CustomerAccountsHeld`) lock: an issue never waits on
 * those, and they never wait on it.
 */
interface CreditAccountsHeld
{
    /** Holds the account of the customer kept for this one: the customer it was merged into, or itself. */
    public function hold(Uuid $companyId, Uuid $keptCustomerId): void;
}
