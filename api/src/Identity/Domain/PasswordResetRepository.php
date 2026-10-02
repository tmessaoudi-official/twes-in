<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

interface PasswordResetRepository
{
    public function ofTokenHash(string $tokenHash): ?PasswordReset;

    /**
     * The links sent to this account that have not been used.
     *
     * @return list<PasswordReset>
     */
    public function openFor(User $user): array;

    public function save(PasswordReset $reset): void;

    public function remove(PasswordReset $reset): void;
}
