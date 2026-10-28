<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform\Output;

use ApiPlatform\Metadata\ApiProperty;

final readonly class MeCompany
{
    public function __construct(
        #[ApiProperty(required: true)] public string $id,
        #[ApiProperty(required: true)] public string $name,
        #[ApiProperty(required: true)] public string $countryCode,
        #[ApiProperty(required: true)] public string $currency,
        #[ApiProperty(required: true)] public string $locale,
        #[ApiProperty(required: true)] public string $timezone,
        #[ApiProperty(required: true)] public string $status,
        /** the user's role name in this company */
        #[ApiProperty(required: true)] public string $role,
        /** what the company's subscription lets its members do on top of their role: full, read_only or locked */
        #[ApiProperty(required: true, schema: ['type' => 'string', 'enum' => ['full', 'read_only', 'locked']])] public string $access = 'full',
        /** null when licensing does not manage the company */
        #[ApiProperty(required: true)] public ?MeSubscription $subscription = null,
        /** the id of the company's stored logo, which changes with it; null without one */
        #[ApiProperty(required: true)] public ?string $logoVersion = null,
        /** a closed company's terms, null while it works */
        #[ApiProperty(required: true)] public ?MeClosing $closing = null,
        /** whether an operator refused the company before it was ever opened, rather than suspended it */
        #[ApiProperty(required: true)] public bool $refused = false,
        /** why an operator refused or suspended it, null when they did not say or while it is open */
        #[ApiProperty(required: true)] public ?string $suspensionReason = null,
    ) {
    }
}
