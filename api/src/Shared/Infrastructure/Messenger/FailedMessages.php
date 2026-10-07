<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;

/**
 * What the worker gave up on, after its retries, for the platform operator (docs/SPEC.md § 7, Messenger transport,
 * row 56): a signup ask or a password reset belongs to no company, so nobody else can see that it never left. The
 * messages wait in the failed transport until the operator runs `messenger:failed:retry` on the worker. An invitation
 * is also shown to its company, in « À surveiller ».
 */
#[ApiResource(
    shortName: 'FailedMessages',
    operations: [new Get(uriTemplate: '/platform/failed-messages', provider: FailedMessagesProvider::class, security: 'is_granted("'.self::PERMISSION.'")')],
)]
final readonly class FailedMessages
{
    /** Held by every operator, as every `platform.*` permission is. */
    public const string PERMISSION = 'platform.messages.read';

    /**
     * @param int                     $total how many messages wait in the failed transport
     * @param list<FailedMessageKind> $kinds what they were, the most first
     */
    public function __construct(
        #[ApiProperty(required: true)] public int $total,
        #[ApiProperty(required: true)] public array $kinds,
    ) {
    }
}
