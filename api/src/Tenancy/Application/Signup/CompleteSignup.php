<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Signup;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Identity\Application\BreachedPasswordCheck;
use App\Identity\Application\PasswordHasher;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Company\NewCompany;
use App\Tenancy\Application\Company\UnknownRole;
use App\Tenancy\Application\Invitation\AcceptInvitation;
use App\Tenancy\Application\Invitation\PasswordBreached;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use App\Tenancy\Domain\SignupRepository;
use Psr\Clock\ClockInterface;

/**
 * The far end of a signup link, reached logged out: it makes the account, opens the company with its country's fiscal
 * preset, makes the account its owner and uses the link up. Everything that can refuse is checked before anything is
 * written, so a refusal leaves no account behind and the link still usable. The company waits for an operator's
 * approval unless the platform turned that off (docs/SPEC.md § 7, 2026-09-15). No session is started: the owner signs
 * in, as after an invitation.
 */
final readonly class CompleteSignup
{
    public const string ENTITY_TYPE = 'signup';
    public const string COMPLETED = 'signup.completed';

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
    }
}
