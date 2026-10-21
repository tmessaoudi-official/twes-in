<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Customers\Infrastructure\Invoices;

use App\Module\Invoices\Application\Statement\MergedCustomers;
use App\Shared\Infrastructure\Doctrine\KeptRecords;
use App\Shared\Infrastructure\Doctrine\Rows;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/** Merges are one level deep, so the customer and the ones pointing at it are the whole account. */
final readonly class DbalMergedCustomers implements MergedCustomers
{
    public function __construct(private Connection $connection)
    {
    }

    public function accountOf(Uuid $companyId, Uuid $customerId): array
    {
        return Rows::texts($this->connection->fetchFirstColumn(
            'SELECT c.id::text FROM customer c WHERE c.company_id = :company AND '.KeptRecords::among('c.id', 'customer', ':customer').'
             ORDER BY c.id <> :customer, c.id',
            ['company' => $companyId->toRfc4122(), 'customer' => $customerId->toRfc4122()],
        ));
    }
}
