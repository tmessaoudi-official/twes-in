<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use App\Module\DeliveryNotes\Domain\DeliveryNoteSearch;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Shared\Domain\InvalidFilter;
use App\Shared\Domain\ListFilters;

/**
 * The one reading of a delivery notes list's query string, for the list, its status counts and its CSV and Excel files,
 * so that what the screen shows, counts and downloads is narrowed by the same words (docs/SPEC.md § 7, 2026-10-06).
 */
final class DeliveryNoteSearchReader
{
    /**
     * @param array<array-key, mixed>     $parameters the query string
     * @param array<string, 'asc'|'desc'> $order      what the caller read of `order[…]`
     *
     * @throws InvalidFilter
     */
    public static function read(array $parameters, ?string $text, array $order): DeliveryNoteSearch
    {
        $filters = new ListFilters($parameters);
        $issueDate = $filters->dateRange('issueDate');
        $deliveryDate = $filters->dateRange('deliveryDate');

        return new DeliveryNoteSearch(
            $text,
            array_map(DeliveryNoteStatus::from(...), $filters->choices('status', array_column(DeliveryNoteStatus::cases(), 'value'))),
            $filters->uuids('customerId'),
            $order,
            $issueDate->isOpen() ? null : $issueDate,
            $deliveryDate->isOpen() ? null : $deliveryDate,
        );
    }
}
