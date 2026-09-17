<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Licensing\Application\CompanyAccess;
use App\Licensing\Domain\Access;
use Symfony\Component\Uid\Uuid;

/** Every company at the access a test sets. */
final class FixedCompanyAccess implements CompanyAccess
{
    public function __construct(public Access $access = Access::Full)
    {
    }

    public function accessOf(Uuid $companyId): Access
    {
        return $this->access;
    }
}
