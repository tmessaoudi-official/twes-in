<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Application;

use App\Licensing\Domain\Access;
use Symfony\Component\Uid\Uuid;

/** What a company's subscription lets its members do now; full for a company licensing does not manage. */
interface CompanyAccess
{
    public function accessOf(Uuid $companyId): Access;
}
