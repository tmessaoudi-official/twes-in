<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use App\Shared\Application\Transactions;
use App\Tenancy\Domain\InvitationRepository;
use App\Tenancy\Domain\InvitationToken;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The worker's side of an invitation: its link made and mailed, away from the request that wrote it. An invitation
 * replaced, accepted or expired meanwhile is mailed nothing, so only the open one ever reaches an inbox.
 */
final readonly class MailInvitation
{
    public function __construct(
        private InvitationRepository $invitations,
        private InvitationMailer $mailer,
        private ClockInterface $clock,
        private Transactions $transactions,
        #[Autowire(param: 'app.invitation.accept_url')]
        private string $acceptUrlTemplate,
    ) {
    }

    public function handle(Uuid $invitationId): void
    {
        $mail = $this->transactions->run(function () use ($invitationId): ?InvitationMail {
            $invitation = $this->invitations->ofId($invitationId);
            if (null === $invitation || !$invitation->isUsableAt($this->clock->now())) {
                return null;
            }
            $token = InvitationToken::generate();
            $invitation->renewToken($token);
            $this->invitations->save($invitation);
            $company = $invitation->getCompany();

            return new InvitationMail(
                $invitation->getEmail()->value,
                $company->getName(),
                $invitation->getRoleName(),
                $invitation->getInvitedBy()?->getDisplayName(),
                str_replace('{token}', $token->raw, $this->acceptUrlTemplate),
                $company->getLocale(),
                $company->getTimezone(),
                $invitation->getExpiresAt(),
            );
        });

        // Mailed once the new link is committed, so the link in the mail is the one that works.
        if (null !== $mail) {
            $this->mailer->send($mail);
        }
    }
}
