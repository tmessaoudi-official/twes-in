<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\FirstSteps;

use App\FirstSteps\Application\DeclaresFirstStep;
use App\Module\Inventory\Infrastructure\ApiPlatform\StockPermission;
use App\Module\Inventory\Infrastructure\Module\InventoryModule;
use App\Tenancy\Domain\Company;
use Doctrine\DBAL\Connection;

/**
 * « Dessiner votre dépôt »: done once the company has a floor to draw on, the first thing the stock map asks for.
 * Asked of whoever may arrange the stock, while the stock is kept; the floors are the venue's, read here by their
 * company because this step speaks of them for the stock map.
 */
final readonly class StockMapStep implements DeclaresFirstStep
{
    public function __construct(private Connection $connection)
    {
    }

    public function key(): string
    {
        return 'stock.map';
    }

    public function position(): int
    {
        return 45;
    }

    public function module(): string
    {
        return InventoryModule::KEY;
    }

    public function permission(): string
    {
        return StockPermission::WRITE;
    }

    public function isDone(Company $company): bool
    {
        return true === $this->connection->fetchOne('SELECT EXISTS (SELECT 1 FROM venue_area WHERE company_id = ?)', [$company->getId()->toRfc4122()]);
    }
}
