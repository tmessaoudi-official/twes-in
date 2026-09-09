<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Domain\Email;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Application\Company\AddMember;
use App\Tenancy\Application\Company\AddMemberRequest;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationRepository;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\RoleRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one way somebody is offered a place in a company. An address that already has an account joins
 * straight away, with no acceptance step and no password to set, because they proved that address when the
 * account was made; anything else is a mailed invitation (docs/SPEC.md § 7, 2026-09-09).
 */
final readonly class InviteToCompany
{
    public const string ENTITY_TYPE = 'invitation';
    public const string SENT = 'invitation.sent';

    public function __construct(
        private CompanyRepository $companies,
        private UserRepository $users,
        private RoleRepository $roles,
        private InvitationRepository $invitations,
        private AddMember $addMember,
        private InvitationMailer $mailer,
        private Notifications $notifications,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private string $acceptUrlTemplate,
        private string $validFor,
    ) {
    }

    /** @throws CompanyNotFound|UnknownRole */
    public function handle(InviteRequest $request, ?Uuid $actorUserId): InviteOutcome
    {
        $company = $this->companies->ofId($request->companyId)
            ?? throw new CompanyNotFound(\sprintf('No company %s.', $request->companyId->toRfc4122()));

        // Checked before anything is written or sent, so a bad role cannot leave a half-made invitation behind.
        $this->roles->builtIn($request->roleName)
            ?? throw new UnknownRole(\sprintf('"%s" is not a built-in role.', $request->roleName));

        $email = Email::fromString($request->email);
        $existing = $this->users->ofEmail($email);
        $now = $this->clock->now();

        if (null !== $existing) {
            $membership = $this->addMember->handle(
                new AddMemberRequest($company->getId(), $email->value, $request->roleName),
                $actorUserId,
            );
            $this->notifications->publish(new Notification(
                'user:'.$existing->getId()->toRfc4122(),
                AddMember::ADDED,
                ['company_id' => $company->getId()->toRfc4122(), 'company' => $company->getName(), 'role' => $request->roleName],
            ));

            return new InviteOutcome(true, $email->value, $membership->getRole()->getName(), $existing->getId()->toRfc4122());
        }

        // Inviting again replaces the open invitation: two live tokens for one address is one more than anybody needs.
        $open = $this->invitations->pendingFor($company->getId(), $email->value);
        if (null !== $open) {
            $this->invitations->remove($open);
        }

        $token = InvitationToken::generate();
        $invitation = new Invitation(
            $company,
            $email,
            $request->roleName,
            $token,
            $now,
            new \DateInterval($this->validFor),
            null === $actorUserId ? null : $this->users->ofId($actorUserId),
        );
        $this->invitations->save($invitation);

        $this->mailer->send(new InvitationMail(
            $email->value,
            $company->getName(),
            $request->roleName,
            $invitation->getInvitedBy()?->getDisplayName(),
            str_replace('{token}', $token->raw, $this->acceptUrlTemplate),
            $company->getLocale(),
            $company->getTimezone(),
            $invitation->getExpiresAt(),
        ));

        // The token is deliberately absent from what is recorded: audit rows are read by people.
        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $invitation->getId(),
            self::SENT,
            $actorUserId,
            ['email' => $email->value, 'role' => $request->roleName, 'expires_at' => $invitation->getExpiresAt()->format(\DATE_ATOM)],
            $company->getId(),
        ));

        return new InviteOutcome(false, $email->value, $request->roleName);
    }
}
