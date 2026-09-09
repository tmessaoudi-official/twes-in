<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface UserRepository
{
    public function ofId(Uuid $id): ?User;

    public function ofEmail(Email $email): ?User;

    /** Makes the user and every change to it durable. */
    public function save(User $user): void;
}
