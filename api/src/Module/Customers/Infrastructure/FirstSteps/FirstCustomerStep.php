<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\Customers\Infrastructure\Module\CustomersModule;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Connection;

/** « Un premier client »: done once the company has one, created or imported. */
final readonly class FirstCustomerStep implements DeclaresFirstStep
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'customers.first';
    }

    public function position(): int
    {
        return 30;
    }

    public function module(): string
    {
        return CustomersModule::KEY;
    }

    public function permission(): string
    {
        return CustomerPermission::WRITE;
    }

    public function isDone(Company $company): bool
    {
        return true === $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM customer WHERE company_id = ?)', [$company->getId()->toRfc4122()]);
    }
}
