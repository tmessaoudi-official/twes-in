<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Application\Password;

/** Everything the reset mail needs, with no idea how it is rendered or sent. */
final readonly class PasswordResetMail
{
    public function __construct(
        public string $to,
        public string $resetUrl,
        public string $locale,
        public int $validForMinutes,
    ) {
    }
}
