<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Identity\Application\BreachedPasswordCheck;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Shared\Application\Notification;
use App\Shared\Application\Notifications;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Company\NewCompany;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\PasswordBreached;
use App\Tenancy\Application\Member\UnknownRole;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Domain\Repository\RoleRepository;
use App\Tenancy\Domain\Repository\SignupRepository;
use Psr\Clock\ClockInterface;

/**
 * The far end of a signup link, reached logged out: it makes the account, opens the company with its country's fiscal
 * preset, makes the account its owner and uses the link up. Everything that can refuse is checked before anything is
 * written, so a refusal leaves no account behind and the link still usable. The company waits for an operator's
 * approval unless the platform turned that off (docs/SPEC.md § 7, 2026-09-15). No session is started: the owner signs
 * in, as after an invitation.
 */
final readonly class CompleteSignup implements DeclaresAuditActions
{
    public const string ENTITY_TYPE = 'signup';
    public const string COMPLETED = 'signup.completed';
    /** The operators' notification of a company waiting for their approval. */
    public const string AWAITING_APPROVAL = 'signup.awaiting_approval';

    public function __construct(
        private SignupLinks $links,
        private SignupPolicy $policy,
        private SignupRepository $signups,
        private UserRepository $users,
        private CompanyRepository $companies,
        private RoleRepository $roles,
        private MembershipRepository $memberships,
        private FiscalPresets $presets,
        private CreateCompany $createCompany,
        private PasswordHasher $hasher,
        private BreachedPasswordCheck $breachedPasswords,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private Notifications $notifications,
    ) {
    }

    /** @throws SignupNotUsable|InvalidSignup|PasswordBreached|CompanyNameTaken|UnknownRole */
    public function handle(CompleteSignupRequest $request): CompleteSignupOutcome
    {
        $signup = $this->links->usable($request->rawToken) ?? throw new SignupNotUsable('That signup link cannot be used.');

        if (!$this->presets->has($request->countryCode)) {
            throw new InvalidSignup(\sprintf('countryCode: there is no fiscal preset for %s, so a company there could not invoice.', $request->countryCode));
        }
        if (!\in_array($request->timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidSignup(\sprintf('timezone: %s is not a time zone.', $request->timezone));
        }
        if (null !== $this->companies->ofName($request->companyName)) {
            throw new CompanyNameTaken(\sprintf('A company named "%s" already exists.', $request->companyName));
        }
        $owner = $this->roles->builtIn(Role::OWNER) ?? throw new UnknownRole('The owner role is not seeded.');
        $breached = $this->breachedPasswords->isBreached($request->plainPassword);
        if (true === $breached) {
            throw new PasswordBreached('That password has appeared in a data breach; choose another.');
        }

        $preset = $this->presets->get($request->countryCode);
        // The company prints in the person's language when its country documents in it, and in the country's otherwise.
        $companyLocale = \in_array($signup->getLocale(), $preset->documentLanguages, true) ? $signup->getLocale() : ($preset->documentLanguages[0] ?? $signup->getLocale());
        $now = $this->clock->now();

        $outcome = $this->transactions->run(function () use ($request, $signup, $preset, $companyLocale, $owner, $breached, $now): CompleteSignupOutcome {
            $user = new User($signup->getEmail(), $request->displayName, $signup->getLocale(), $now);
            $user->setPasswordHash($this->hasher->hash($request->plainPassword), $now);
            $this->users->save($user);

            $company = $this->createCompany->handle(new NewCompany($request->companyName, $request->countryCode, $preset->currency, $companyLocale, $request->timezone), $user->getId());
            $this->memberships->save(new Membership($user, $company, $owner, $now));
            if (!$this->policy->approvalRequired()) {
                $company->activate($now);
                $this->companies->save($company);
            }

            $signup->complete($now);
            $this->signups->save($signup);

            $this->audit->record(new AuditEntry(
                self::ENTITY_TYPE,
                $signup->getId(),
                self::COMPLETED,
                $user->getId(),
                ['email' => $user->getEmail()->value, 'company' => $company->getName(), 'status' => $company->getStatus()],
                $company->getId(),
            ));
            if (null === $breached) {
                $this->audit->record(new AuditEntry(
                    self::ENTITY_TYPE,
                    $signup->getId(),
                    AcceptInvitation::BREACH_CHECK_SKIPPED,
                    $user->getId(),
                    ['email' => $user->getEmail()->value, 'reason' => 'the breach service could not be reached'],
                    $company->getId(),
                ));
            }

            return new CompleteSignupOutcome($user->getId()->toRfc4122(), $company->getName(), $company->getStatus());
        });
        if (Company::STATUS_PENDING === $outcome->companyStatus) {
            $this->tellOperators($outcome->companyName, $signup->getEmail()->value);
        }

        return $outcome;
    }

    /** A company waits for their approval: nobody saw it until they opened the platform's page again. */
    private function tellOperators(string $companyName, string $email): void
    {
        foreach ($this->users->platformOperators() as $operator) {
            $this->notifications->publish(new Notification('user:'.$operator->getId()->toRfc4122(), self::AWAITING_APPROVAL, ['company' => $companyName, 'email' => $email]));
        }
    }

    public function auditActions(): array
    {
        return [
            AcceptInvitation::BREACH_CHECK_SKIPPED => AuditScope::Company,
            self::COMPLETED => AuditScope::Company,
        ];
    }
}
