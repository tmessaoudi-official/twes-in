<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Member\RoleBounds;
use App\Tenancy\Application\Member\RoleNotManageable;
use App\Tenancy\Domain\Repository\InvitationRepository;
use Symfony\Component\Uid\Uuid;

/**
 * Takes back an invitation nobody has accepted, sent to a mistyped address or no longer wanted: its link stops working
 * at once. Whoever may not give its role may not take it back either.
 */
final readonly class WithdrawInvitation implements DeclaresAuditActions
{
    public const string WITHDRAWN = 'invitation.withdrawn';

    public function __construct(
        private InvitationRepository $invitations,
        private RoleBounds $bounds,
        private AuditTrail $audit,
        private Transactions $transactions,
    ) {
    }

    /** @throws InvitationNotFound|RoleNotManageable */
    public function handle(Uuid $companyId, Uuid $invitationId, ?Uuid $actorUserId): void
    {
        $this->transactions->run(function () use ($companyId, $invitationId, $actorUserId): void {
            $invitation = $this->invitations->ofId($invitationId);
            if (null === $invitation || !$invitation->getCompany()->getId()->equals($companyId) || null !== $invitation->getAcceptedAt()) {
                throw new InvitationNotFound(\sprintf('No open invitation %s here.', $invitationId->toRfc4122()));
            }
            // A role deleted since leaves the invitation its name alone, and nothing to bound.
            $role = $invitation->getRole();
            if (null !== $role) {
                $this->bounds->assertMayGrant($companyId, $actorUserId, $role);
            }

            $this->invitations->remove($invitation);

            $this->audit->record(new AuditEntry(
                InviteToCompany::ENTITY_TYPE,
                $invitation->getId(),
                self::WITHDRAWN,
                $actorUserId,
                ['email' => $invitation->getEmail()->value, 'role' => $invitation->getRoleName()],
                $companyId,
            ));
        });
    }

    public function auditActions(): array
    {
        return [
            self::WITHDRAWN => new Recorded(InviteToCompany::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
