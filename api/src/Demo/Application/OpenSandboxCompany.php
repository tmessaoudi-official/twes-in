<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Demo\Application;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Demo\Domain\Repository\DemoSandboxRepository;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Identity\Domain\Model\User;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Domain\ValueObject\Email;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Application\Company\NewCompany;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Membership;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use App\Tenancy\Domain\Repository\RoleRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Opens the company of a sandbox being built, active and provisioned with its preset, and the account that owns it: an
 * account made for this sandbox alone, a member of nothing else, with no password, entered only by the demo's own way
 * in. The company's name is the catalogue's followed by « démo » and a mark of the sandbox, for names are unique and
 * the demo company of a development stack keeps the catalogue's own.
 */
final readonly class OpenSandboxCompany implements DeclaresAuditActions
{
    public function __construct(
        private DemoSandboxRepository $sandboxes,
        private UserRepository $users,
        private CompanyRepository $companies,
        private RoleRepository $roles,
        private MembershipRepository $memberships,
        private ProvisionCompany $provision,
        private AuditTrail $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    /** @return array{Uuid, Uuid} the company's id and its owner's */
    public function handle(Uuid $sandboxId, NewCompany $catalogue): array
    {
        return $this->transactions->run(function () use ($sandboxId, $catalogue): array {
            $sandbox = $this->sandboxes->lockedOfId($sandboxId) ?? throw new \LogicException('A sandbox being built vanished.');
            $now = $this->clock->now();
            $mark = str_replace('-', '', $sandboxId->toRfc4122());
            $name = \sprintf('%s · démo %s', $catalogue->name, strtoupper(substr($mark, -6)));
            if (null !== $this->companies->ofName($name)) {
                throw new \LogicException("A company is already named $name.");
            }

            $locale = $catalogue->locale ?? throw new \LogicException('A catalogue company names the language it prints in.');
            $owner = new User(Email::fromString("demo-$mark@example.com"), 'Démo', $locale, $now);
            $this->users->save($owner);
            $company = new Company($name, $catalogue->countryCode, $catalogue->currency, $locale, $catalogue->timezone, $now);
            $this->companies->save($company);
            $this->provision->handle($company);
            $role = $this->roles->builtIn(Role::OWNER) ?? throw new \LogicException('The owner role is seeded at every start.');
            $this->memberships->save(new Membership($owner, $company, $role, $now));
            $this->audit->record(new AuditEntry(
                CreateCompany::ENTITY_TYPE,
                $company->getId(),
                CreateCompany::CREATED,
                null,
                ['name' => $company->getName(), 'status' => $company->getStatus(), 'fiscal_preset' => $company->getFiscalPreset()],
                $company->getId(),
            ));

            $sandbox->opened($company, $owner);
            $this->sandboxes->save($sandbox);

            return [$company->getId(), $owner->getId()];
        });
    }

    public function auditActions(): array
    {
        return [
            CreateCompany::CREATED => AuditScope::Company,
        ];
    }
}
