<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use App\Shared\Domain\Page;
use App\Shared\Domain\PageRequest;
use Symfony\Component\Uid\Uuid;

interface UserRepository
{
    public function ofId(Uuid $id): ?User;

    public function ofEmail(Email $email): ?User;

    /**
     * One page of the accounts the platform's operators list, narrowed and sorted in the database.
     *
     * @return Page<User>
     */
    public function search(AccountSearch $search, PageRequest $page): Page;

    /**
     * The accounts that run the platform, in address order: who hears of what a company declares.
     *
     * @return list<User>
     */
    public function platformOperators(): array;

    /** Makes the user and every change to it durable. */
    public function save(User $user): void;
}
