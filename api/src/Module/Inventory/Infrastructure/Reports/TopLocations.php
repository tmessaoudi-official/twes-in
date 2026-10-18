<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\Reports;

use App\Module\Reports\Application\ReportContext;
use Doctrine\DBAL\Connection;

/**
 * The location a stock report's row opens the movements list on when it is narrowed to an establishment: the top of
 * that establishment's tree, its default location, under which the list finds everything that moved there.
 */
final readonly class TopLocations
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * The filters a row's drill-down adds for the report's establishment, none for the whole company. Asked only once
     * the establishment's locations moved goods, so it has its tree.
     *
     * @return array<string, string>
     */
    public function filters(ReportContext $context): array
    {
        if (null === $context->establishmentId) {
            return [];
        }
        $id = $this->connection->fetchOne(
            'SELECT id FROM stock_location WHERE company_id = :company AND establishment_id = :establishment AND is_default',
            ['company' => $context->company->getId()->toRfc4122(), 'establishment' => $context->establishmentId],
        );

        return ['location' => \is_string($id) ? $id : throw new \LogicException('An establishment whose locations moved goods has no top location.')];
    }
}
