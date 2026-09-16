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
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\AlreadyAMember;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\RoleBounds;
use App\Tenancy\Application\Company\RoleNotManageable;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationRepository;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\RoleRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The one way somebody is offered a place in a company: a mailed invitation, whether or not the address already
 * has an account. Nobody is a member of the company, nor held by its MFA requirement, until they accept the link;
 * an address that has an account is also told in the application (docs/SPEC.md § 7, 2026-09-15).
 */
final readonly class InviteToCompany
{
    public const string ENTITY_TYPE = 'invitation';
    public const string SENT = 'invitation.sent';
    public const string RECEIVED = 'invitation.received';

    public function __construct(
        private CompanyRepository $companies,
        private UserRepository $users,
        private RoleRepository $roles,
        private RoleBounds $bounds,
        private InvitationRepository $invitations,
        private MembershipRepository $memberships,
        private InvitationMailer $mailer,
        private Notifications $notifications,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private string $acceptUrlTemplate,
        private string $validFor,
        private Transactions $transactions,
    ) {
    }

    /** @throws CompanyNotFound|UnknownRole|RoleNotManageable|AlreadyAMember */
    public function handle(InviteRequest $request, ?Uuid $actorUserId): InviteOutcome
    {
        [$company, $email, $existing, $token, $invitation] = $this->transactions->run(function () use ($request, $actorUserId): array {
            $company = $this->companies->ofId($request->companyId)
                ?? throw new CompanyNotFound(\sprintf('No company %s.', $request->companyId->toRfc4122()));

            // Checked before anything is written or sent, so a bad role cannot leave a half-made invitation behind.
            $this->roles->builtIn($request->roleName)
                ?? throw new UnknownRole(\sprintf('"%s" is not a built-in role.', $request->roleName));
            $this->bounds->assertMayGrant($company->getId(), $actorUserId, $request->roleName);

            $email = Email::fromString($request->email);
            $existing = $this->users->ofEmail($email);
            if (null !== $existing && null !== $this->memberships->ofUserInCompany($existing->getId(), $company->getId())) {
                throw new AlreadyAMember(\sprintf('%s already belongs to %s.', $email->value, $company->getName()));
            }
            $now = $this->clock->now();

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

            // The token is deliberately absent from what is recorded: audit rows are read by people.
            $this->audit->record(new AuditEntry(
                self::ENTITY_TYPE,
                $invitation->getId(),
                self::SENT,
                $actorUserId,
                ['email' => $email->value, 'role' => $request->roleName, 'expires_at' => $invitation->getExpiresAt()->format(\DATE_ATOM)],
                $company->getId(),
            ));

            return [$company, $email, $existing, $token, $invitation];
        });

        // Mailed once the invitation is committed, so a link never points at an invitation that was rolled back.
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

        // The link stays in the mail: a notification is stored and shown to whoever holds the session later.
        if (null !== $existing) {
            $this->notifications->publish(new Notification(
                'user:'.$existing->getId()->toRfc4122(),
                self::RECEIVED,
                ['company_id' => $company->getId()->toRfc4122(), 'company' => $company->getName(), 'role' => $request->roleName],
            ));
        }

        return new InviteOutcome($email->value, $request->roleName);
    }
}
