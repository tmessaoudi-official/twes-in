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

    /** @return list<User> the users whose address or display name holds that text, whatever its case, in address order */
    public function search(string $text, int $limit): array;

    /**
     * The accounts that run the platform, in address order: who hears of what a company declares.
     *
     * @return list<User>
     */
    public function platformOperators(): array;

    /** Makes the user and every change to it durable. */
    public function save(User $user): void;
}
