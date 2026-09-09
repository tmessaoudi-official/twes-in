<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Domain;

use Symfony\Component\Uid\Uuid;

interface InvitationRepository
{
    /** Looked up by hash: the raw token is never stored, so it is never compared. */
    public function ofTokenHash(string $tokenHash): ?Invitation;

    /** The invitation still open for that address in that company, if any. */
    public function pendingFor(Uuid $companyId, string $email): ?Invitation;

    public function save(Invitation $invitation): void;

    /** Inviting the same address again replaces the open invitation, so exactly one token ever works. */
    public function remove(Invitation $invitation): void;
}
