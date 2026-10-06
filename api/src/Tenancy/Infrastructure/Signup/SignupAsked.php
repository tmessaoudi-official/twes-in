<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\Signup;

/**
 * An address asked to sign up, queued for the worker (docs/SPEC.md § 7, 2026-10-06): the request queues one for every
 * address it accepts, so one with an account costs it no more than a new one, and the worker looks it up, makes the
 * link or the "you already have an account" mail and sends it. It carries the address and the language only.
 */
final readonly class SignupAsked
{
    public function __construct(public string $email, public ?string $locale)
    {
    }
}
