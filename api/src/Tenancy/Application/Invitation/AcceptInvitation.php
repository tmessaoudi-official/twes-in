<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Invitation;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Identity\Application\BreachedPasswordCheck;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Invitation;
use App\Tenancy\Domain\InvitationRepository;
use App\Tenancy\Domain\InvitationToken;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use Psr\Clock\ClockInterface;

/**
 * The far end of an invitation link, reached logged out: it makes the account, joins the company and uses
 * the invitation up. Two rules are worth stating because they are security, not behaviour:
 *
 * - a link whose address has an account joins that account, asks for nothing and does NOT touch its password.
 *   A mailed link that could set an existing account's password is an account takeover, not a convenience.
 * - a breached password is refused, and a breach service that cannot be reached accepts the password and
 *   records the skip, so the gap is visible afterwards (docs/SPEC.md § 7, 2026-09-09).
 */
final readonly class AcceptInvitation
{
    public const string ENTITY_TYPE = 'invitation';
    public const string ACCEPTED = 'invitation.accepted';
    public const string BREACH_CHECK_SKIPPED = 'password.breach_check_skipped';

    public function __construct(
        private InvitationRepository $invitations,
        private UserRepository $users,
        private MembershipRepository $memberships,
        private RoleRepository $roles,
        private CompanyRepository $companies,
        private PasswordHasher $hasher,
        private BreachedPasswordCheck $breachedPasswords,
        private Notifications $notifications,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @throws InvitationNotUsable|AccountDetailsRequired|PasswordBreached|UnknownRole */
    public function handle(AcceptRequest $request): AcceptOutcome
    {
        $invitation = $this->usable($request->rawToken);
        $company = $invitation->getCompany();
        $role = $this->roles->builtIn($invitation->getRoleName())
            ?? throw new UnknownRole(\sprintf('"%s" is not a built-in role.', $invitation->getRoleName()));

        $now = $this->clock->now();
        $user = $this->users->ofEmail($invitation->getEmail());
        $passwordSet = null === $user;

        if (null === $user) {
            if (null === $request->displayName || null === $request->plainPassword) {
                throw new AccountDetailsRequired('A new account needs a name and a password.');
            }
            $this->refuseABreachedPassword($request->plainPassword, $invitation);
            $user = new User($invitation->getEmail(), $request->displayName, $company->getLocale(), $now);
            $user->setPasswordHash($this->hasher->hash($request->plainPassword), $now);
            $this->users->save($user);
        }

        if (null === $this->memberships->ofUserInCompany($user->getId(), $company->getId())) {
            $this->memberships->save(new Membership($user, $company, $role, $now));
        }

        if (Role::OWNER === $role->getName()) {
            $company->activate($now);
            $this->companies->save($company);
        }

        $invitation->accept($now);
        $this->invitations->save($invitation);

        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $invitation->getId(),
            self::ACCEPTED,
            $user->getId(),
            ['email' => $invitation->getEmail()->value, 'role' => $role->getName(), 'password_set' => $passwordSet],
            $company->getId(),
        ));

        $this->notifications->publish(new Notification(
            'company:'.$company->getId()->toRfc4122(),
            self::ACCEPTED,
            ['user_id' => $user->getId()->toRfc4122(), 'display_name' => $user->getDisplayName(), 'role' => $role->getName()],
        ));

        return new AcceptOutcome(
            $user->getId()->toRfc4122(),
            $company->getId()->toRfc4122(),
            $company->getName(),
            $role->getName(),
            $passwordSet,
        );
    }

    private function usable(string $rawToken): Invitation
    {
        try {
            $token = InvitationToken::fromRaw($rawToken);
        } catch (\InvalidArgumentException $malformed) {
            throw new InvitationNotUsable('That invitation cannot be used.', 0, $malformed);
        }

        $invitation = $this->invitations->ofTokenHash($token->hash());
        if (null === $invitation || !$invitation->isUsableAt($this->clock->now())) {
            throw new InvitationNotUsable('That invitation cannot be used.');
        }

        return $invitation;
    }

    /** @throws PasswordBreached */
    private function refuseABreachedPassword(string $plainPassword, Invitation $invitation): void
    {
        $breached = $this->breachedPasswords->isBreached($plainPassword);
        if (true === $breached) {
            throw new PasswordBreached('That password has appeared in a data breach; choose another.');
        }
        if (null === $breached) {
            $this->audit->record(new AuditEntry(
                self::ENTITY_TYPE,
                $invitation->getId(),
                self::BREACH_CHECK_SKIPPED,
                null,
                ['email' => $invitation->getEmail()->value, 'reason' => 'the breach service could not be reached'],
                $invitation->getCompany()->getId(),
            ));
        }
    }
}
