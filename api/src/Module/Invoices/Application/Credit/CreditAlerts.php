<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Application\Credit;

use App\Shared\Application\Notification;

/**
 * The alerts an issue decided inside its transaction, told only once that transaction has committed: an invoice whose
 * issue rolls back took nobody past a limit.
 */
interface CreditAlerts
{
    public function onceCommitted(Notification ...$notifications): void;
}
