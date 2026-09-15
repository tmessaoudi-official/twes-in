<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Domain;

use Symfony\Component\Uid\Uuid;

interface PasskeyRepository
{
    /** @return list<Passkey> oldest first */
    public function ofUser(User $user): array;

    /** Scoped by user: someone else's passkey is exactly as absent as one that does not exist. */
    public function ofUserAndId(User $user, Uuid $id): ?Passkey;

    public function ofUserAndCredentialId(User $user, string $credentialId): ?Passkey;

    /** Across every account: an authenticator creates one credential id per registration, so a repeat is a replay. */
    public function existsWithCredentialId(string $credentialId): bool;

    public function countFor(User $user): int;

    public function save(Passkey $passkey): void;

    public function remove(Passkey $passkey): void;
}
