<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Infrastructure\ApiPlatform;

use App\Audit\Application\ActivitySearch;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;
use App\Tenancy\Domain\Company;

/** The one reading of the journal's query string, for the list and for its file, so both show the same entries. */
final class ActivitySearchReader
{
    /**
     * @param array<array-key, mixed> $parameters the query string
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, Company $company): ActivitySearch
    {
        $filters = new ListFilters($parameters);
        $entity = $filters->uuids('entityId');
        $order = $parameters['order'] ?? null;

        return new ActivitySearch(
            $text,
            $filters->uuids('actorId'),
            $filters->matching('entityType', '/'.ActivityResource::WORD.'/'),
            $entity[0] ?? null,
            $filters->matching('action', '/'.ActivityResource::ACTION.'/'),
            $filters->dateRange('at'),
            \is_array($order) && 'asc' === ($order['at'] ?? null) ? 'asc' : 'desc',
            $company->getTimezone(),
        );
    }
}
