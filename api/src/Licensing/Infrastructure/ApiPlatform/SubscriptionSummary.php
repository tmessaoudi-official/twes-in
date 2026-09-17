<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\ApiProperty;
use App\Licensing\Domain\Standing;
use App\Tenancy\Infrastructure\ApiPlatform\PlatformCompanyResource;
use Symfony\Component\Serializer\Attribute\Groups;

/** Where a company stands in its subscription, as the platform's company list shows it. */
final readonly class SubscriptionSummary
{
    public function __construct(
        #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['trial', 'paid', 'grace', 'held', 'unpaid']])]
        #[Groups([PlatformCompanyResource::READ])]
        public string $stage,
        #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['full', 'read_only', 'locked']])]
        #[Groups([PlatformCompanyResource::READ])]
        public string $access,
        #[ApiProperty(required: true)]
        #[Groups([PlatformCompanyResource::READ])]
        public string $coveredUntil,
        #[ApiProperty(required: true, schema: ['type' => ['integer', 'null']])]
        #[Groups([PlatformCompanyResource::READ])]
        public ?int $daysLeft,
    ) {
    }

    public static function of(Standing $standing): self
    {
        return new self($standing->stage->value, $standing->access->value, $standing->coveredUntil->format(\DATE_ATOM), $standing->daysLeft);
    }
}
