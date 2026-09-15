<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use App\Identity\Domain\Email;

interface SignupRepository
{
    public function ofTokenHash(string $tokenHash): ?Signup;

    /** The link sent to this address that has not been used, if any. */
    public function openFor(Email $email): ?Signup;

    public function save(Signup $signup): void;

    public function remove(Signup $signup): void;
}
