<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Seed;

use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Regime\SyncCustomerTaxRegimes;
use App\Identity\Application\PasswordHasher;
use App\Identity\Application\SecretCipher;
use App\Identity\Application\TotpCodes;
use App\Identity\Domain\Email;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepository;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\Membership;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Permission;
use App\Tenancy\Domain\Role;
use App\Tenancy\Domain\RoleRepository;
use Psr\Clock\ClockInterface;

/**
 * The built-in roles, the first operator, the first company, the customer tax regimes of every fiscal preset and
 * every company's taxes and units, idempotently: run on an empty database and again after a migration it
 * converges on the same rows. `converge()` is the part a live database needs at every start, without the operator and
 * the first company. A company is unusable without an owner role, so the roles are seeded here too.
 */
final readonly class SeedPlatform
{
    /**
     * @var array<string, list<string>> the four built-in roles and their permission sets. The clerk holds what selling
     *                                  takes and nothing a manager answers for: no credit note, no validated delivery
     *                                  note, no customer record, no cost, no settings, members or roles (docs/SPEC.md
     *                                  § 7, audit 2026-10-06 B-12).
     */
    public const array BUILT_IN_ROLES = [
        Role::OWNER => [Permission::WILDCARD],
        Role::ADMIN => ['company.read', 'company.settings', 'user.read', 'user.write', 'invoice.read', 'invoice.write', 'invoice.issue', 'invoice.credit', 'payment.write', 'customer.read', 'customer.write', 'product.read', 'product.write', 'product.cost.read', 'delivery_note.read', 'delivery_note.write', 'delivery_note.validate', 'quote.read', 'quote.write', 'stock.read', 'stock.write', 'vendor.read', 'vendor.write', 'expense.read', 'expense.write', 'fiscal.read', 'fiscal.write'],
        Role::MEMBER => ['company.read', 'invoice.read', 'invoice.write', 'customer.read', 'customer.write', 'product.read', 'delivery_note.read', 'delivery_note.write', 'quote.read', 'quote.write', 'stock.read', 'vendor.read', 'expense.read', 'fiscal.read'],
        Role::CLERK => ['company.read', 'invoice.read', 'invoice.write', 'invoice.issue', 'payment.write', 'customer.read', 'product.read', 'delivery_note.read', 'delivery_note.write', 'quote.read', 'quote.write', 'stock.read'],
    ];

    public function __construct(
        private RoleRepository $roles,
        private UserRepository $users,
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private PasswordHasher $hasher,
        private SecretCipher $cipher,
        private TotpCodes $totp,
        private SyncCustomerTaxRegimes $regimes,
        private ProvisionCompany $provision,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string> what was created, in order; empty when every row already existed
     *
     * @throws OperatorPasswordRequired
     */
    public function seed(SeedRequest $request): array
    {
        $now = $this->clock->now();
        $email = Email::fromString($request->operatorEmail);
        $operator = $this->users->ofEmail($email);
        if (null === $operator && (null === $request->operatorPassword || '' === $request->operatorPassword)) {
            throw new OperatorPasswordRequired('--operator-password is required to create the operator; none is built in.');
        }
        $totpSecret = $request->operatorTotpSecret;
        if (null !== $totpSecret && 1 !== preg_match('/^[A-Z2-7]{16,}$/', $totpSecret)) {
            throw new InvalidOperatorTotpSecret('--operator-totp-secret must be base32 (A-Z and 2-7), at least 16 characters.');
        }

        $created = $this->builtInRoles($now);

        if (null === $operator) {
            $operator = new User($email, $request->operatorName, $request->locale, $now);
            $operator->setPasswordHash($this->hasher->hash((string) $request->operatorPassword), $now);
            $operator->setPlatformOperator(true);
            $this->users->save($operator);
            $created[] = "operator $email";
        }

        // A known secret is for a development or CI stack, where a script computes the operator's codes. An operator
        // seeded without one enrols their own authenticator at their first sign-in, as every operator must.
        if (null !== $totpSecret && !$operator->hasTotp()) {
            // Confirmed with a step already past, so a code for the moment the seed ends is still unspent.
            $past = $now->sub(new \DateInterval('PT1M'));
            $step = $this->totp->verify($totpSecret, $this->totp->codeAt($totpSecret, $past), $past)
                ?? throw new \LogicException('A code computed from the secret was refused by the same secret.');
            $operator->beginTotpEnrolment($this->cipher->encrypt($totpSecret), $now);
            $operator->confirmTotpEnrolment($step, $now);
            $this->users->save($operator);
            $created[] = "authenticator for $email";
        }

        $company = $this->companies->ofName($request->companyName);
        if (null === $company) {
            $company = new Company($request->companyName, $request->country, $request->currency, $request->locale, $request->timezone, $now);
            $this->companies->save($company);
            $created[] = "company {$request->companyName}";
        }

        if (null === $this->memberships->ofUserInCompany($operator->getId(), $company->getId())) {
            $owner = $this->roles->builtIn(Role::OWNER) ?? throw new \LogicException('The owner role was seeded a moment ago.');
            $this->memberships->save(new Membership($operator, $company, $owner, $now));
            $created[] = "membership $email owns {$request->companyName}";
        }

        return [...$created, ...$this->presets()];
    }

    /**
     * What this release expects of a database an earlier one wrote, and nothing else: the built-in roles' permission
     * sets, the presets' customer tax regimes, and each company's copy of its preset. No operator and no first company,
     * so every start may run it on a live database (`app:platform:converge`, `infra/api/docker-entrypoint.sh`).
     *
     * @return list<string> what was created or brought up, in order; empty when nothing was behind
     */
    public function converge(): array
    {
        return [...$this->builtInRoles($this->clock->now()), ...$this->presets()];
    }

    /** @return list<string> */
    private function builtInRoles(\DateTimeImmutable $now): array
    {
        $created = [];
        foreach (self::BUILT_IN_ROLES as $name => $permissions) {
            $role = $this->roles->builtIn($name);
            if (null === $role) {
                $this->roles->save(new Role($name, $permissions, null, $now));
                $created[] = "role $name";
                continue;
            }
            // A database seeded by an earlier release carries that release's permission set; converge on this one.
            if ($role->redefinePermissions($permissions)) {
                $this->roles->save($role);
                $created[] = "role $name updated";
            }
        }

        return $created;
    }

    /**
     * A preset gains what a release adds (a tax regime, a kind of document to number), and a migration cannot read the
     * preset files: every company copies what its preset has and it lacks.
     *
     * @return list<string>
     */
    private function presets(): array
    {
        $provisioned = [];
        foreach ($this->companies->all() as $each) {
            $provisioned = [...$provisioned, ...$this->provision->handle($each)];
        }

        return [...$this->regimes->handle(), ...$provisioned];
    }
}
