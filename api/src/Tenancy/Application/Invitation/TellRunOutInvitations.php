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
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Repository\InvitationRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;

/**
 * Tells whoever sent an invitation that it ran out unused: nobody watches the members list for a link to lapse, and the
 * person invited may be waiting on a mail they lost. Each is told once, whichever run comes first after it ran out; a
 * sender who left the company, or whose account is gone, is not told, and the invitation is not asked about again.
 * Either way the company hears that it ran out, as a change of the invitation recorded with no actor: nothing else
 * writes it, and a members page left open turns it « expirée » only from that.
 */
final readonly class TellRunOutInvitations implements DeclaresAuditActions
{
    public const string EXPIRED = 'invitation.expired';

    /** The invitations one run reads, so a backlog is told over a few runs rather than in one long transaction. */
    private const int BATCH = 200;

    public function __construct(
        private InvitationRepository $invitations,
        private MembershipRepository $memberships,
        private Notifications $notifications,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return int how many senders were told */
    public function handle(): int
    {
        return $this->transactions->run(function (): int {
            $now = $this->clock->now();
            $told = 0;
            foreach ($this->invitations->runOutUntold($now, self::BATCH) as $invitation) {
                $invitation->runOutTold($now);
                $this->invitations->save($invitation);

                $company = $invitation->getCompany();
                $this->audit->record(new AuditEntry(
                    InviteToCompany::ENTITY_TYPE,
                    $invitation->getId(),
                    self::EXPIRED,
                    null,
                    ['email' => $invitation->getEmail()->value, 'role' => $invitation->getRoleName()],
                    $company->getId(),
                ));
                $sender = $invitation->getInvitedBy();
                if (null === $sender || null === $this->memberships->ofUserInCompany($sender->getId(), $company->getId())) {
                    continue;
                }
                $this->notifications->publish(new Notification(
                    'user:'.$sender->getId()->toRfc4122(),
                    self::EXPIRED,
                    ['company' => $company->getName(), 'email' => $invitation->getEmail()->value],
                    $company->getId()->toRfc4122(),
                ));
                ++$told;
            }

            return $told;
        });
    }

    public function auditActions(): array
    {
        return [self::EXPIRED => new Recorded(InviteToCompany::ENTITY_TYPE, AuditScope::Company)];
    }
}
