<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Audit\Application;

use App\Shared\Domain\DateRange;
use Symfony\Component\Uid\Uuid;

/** What a reader of the journal narrows it to: words, a person, kinds of record, one record, actions and days. */
final readonly class ActivitySearch
{
    /**
     * @param list<Uuid>     $actorIds    the people, any of them; none is everybody
     * @param list<string>   $entityTypes the kinds of record, any of them; none is every kind
     * @param list<string>   $actions     the actions, any of them; none is every action
     * @param DateRange|null $days        days of the company's own calendar
     * @param 'asc'|'desc'   $direction   by moment, newest first unless asked otherwise
     */
    public function __construct(
        public ?string $text = null,
        public array $actorIds = [],
        public array $entityTypes = [],
        public ?Uuid $entityId = null,
        public array $actions = [],
        public ?DateRange $days = null,
        public string $direction = 'desc',
        public string $timezone = 'UTC',
    ) {
    }
}
