<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Products\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Module\Products\Infrastructure\ApiPlatform\ProductPermission;
use App\Module\Products\Infrastructure\Module\ProductsModule;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Connection;

/** « Un premier article »: done once the company has one, created or imported. */
final readonly class FirstProductStep implements DeclaresFirstStep
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'products.first';
    }

    public function position(): int
    {
        return 40;
    }

    public function module(): string
    {
        return ProductsModule::KEY;
    }

    public function permission(): string
    {
        return ProductPermission::WRITE;
    }

    public function isDone(Company $company): bool
    {
        return true === $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM product WHERE company_id = ?)', [$company->getId()->toRfc4122()]);
    }
}
