<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use App\Licensing\Domain\Standing;

/** Where the working company stands in its subscription, so the application can warn before anything closes. */
final readonly class MeSubscription
{
    public function __construct(
        #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['trial', 'paid', 'grace', 'unpaid']])] public string $stage,
        #[ApiProperty(required: true)] public string $coveredUntil,
        #[ApiProperty(required: true)] public string $graceEndsAt,
        /** whole days before the stage changes; null once unpaid */
        #[ApiProperty(required: true, schema: ['type' => ['integer', 'null']])] public ?int $daysLeft,
    ) {
    }

    public static function of(Standing $standing): self
    {
        return new self($standing->stage->value, $standing->coveredUntil->format(\DATE_ATOM), $standing->graceEndsAt->format(\DATE_ATOM), $standing->daysLeft);
    }
}
