<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Application\ShortSales\SaleStock;
use App\Shared\Application\ShortSales\Shortfall;
use App\Shared\Application\ShortSales\ShortSaleRule;
use App\Tenancy\Domain\Model\Company;
use Symfony\Component\Uid\Uuid;

/** The stock's answer where a test is about anything else: every sale allowed, as by default. */
final readonly class AllowingSaleStock implements SaleStock
{
    public function shortfall(Company $company, Uuid $establishmentId, ?Uuid $roleId, array $lines): Shortfall
    {
        return new Shortfall(ShortSaleRule::Allow);
    }
}
