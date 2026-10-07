<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use ApiPlatform\Metadata\ApiProperty;

/** How many failed messages were of one kind, named by the message's class without its namespace. */
final readonly class FailedMessageKind
{
    public function __construct(
        #[ApiProperty(required: true)] public string $kind,
        #[ApiProperty(required: true)] public int $count,
    ) {
    }
}
