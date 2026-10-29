<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Account;

use Symfony\Component\Uid\Uuid;

/** Whether an account owns a company, as the companies' context knows it. */
interface CompanyOwners
{
    public function ownsACompany(Uuid $userId): bool;
}
