<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tests\Support;

use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationRepository;
use Symfony\Component\Uid\Uuid;

final class InMemoryInvitations implements InvitationRepository
{
    /** @var list<Invitation> */
    public array $invitations = [];

    public function ofTokenHash(string $tokenHash): ?Invitation
    {
        foreach ($this->invitations as $invitation) {
            if (hash_equals($invitation->getTokenHash(), $tokenHash)) {
                return $invitation;
            }
        }

        return null;
    }

    public function pendingFor(Uuid $companyId, string $email): ?Invitation
    {
        foreach ($this->invitations as $invitation) {
            if ($invitation->getCompany()->getId()->equals($companyId)
                && $invitation->getEmail()->value === $email
                && null === $invitation->getAcceptedAt()) {
                return $invitation;
            }
        }

        return null;
    }

    public function openOfCompany(Uuid $companyId, \DateTimeImmutable $now): array
    {
        return array_values(array_filter(
            $this->invitations,
            static fn (Invitation $i) => $i->getCompany()->getId()->equals($companyId) && $i->isUsableAt($now),
        ));
    }

    public function save(Invitation $invitation): void
    {
        if (!\in_array($invitation, $this->invitations, true)) {
            $this->invitations[] = $invitation;
        }
    }

    public function remove(Invitation $invitation): void
    {
        $this->invitations = array_values(array_filter($this->invitations, static fn (Invitation $i) => $i !== $invitation));
    }
}
