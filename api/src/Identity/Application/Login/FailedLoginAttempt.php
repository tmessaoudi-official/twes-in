<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Login;

use Symfony\Component\Uid\Uuid;

/**
 * @param bool $wrongPassword true only when the credentials were compared and did not match; a refusal for
 *                            another reason (locked, disabled, throttled, unknown account) is recorded but never counted
 */
final readonly class FailedLoginAttempt
{
    public function __construct(
        public ?Uuid $userId,
        public ?string $email,
        public string $reason,
        public bool $wrongPassword,
    ) {
    }
}
