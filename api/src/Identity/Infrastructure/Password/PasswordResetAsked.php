<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Password;

/**
 * An address asked for a way back into its account, queued for the worker (docs/SPEC.md § 7, 2026-10-06 02:42): the
 * request queues one for every address it accepts, so a registered one costs it no more than an unknown one, and the
 * worker looks the account up, makes the link and mails it. It carries the address and the language only, never a token.
 */
final readonly class PasswordResetAsked
{
    public function __construct(public string $email, public ?string $locale)
    {
    }
}
