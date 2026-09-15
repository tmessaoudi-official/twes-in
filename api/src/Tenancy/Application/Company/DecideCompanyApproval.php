<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Domain\MembershipRepository;
use App\Tenancy\Domain\Role;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * An operator's decision on a company that signed up (docs/SPEC.md § 7, 2026-09-15). Approving opens it to its members
 * and tells its owners; rejecting suspends it. A decision the company already carries changes nothing: no second mail,
 * no second audit entry.
 */
final readonly class DecideCompanyApproval
{
    public const string APPROVED = 'company.approved';
    public const string REJECTED = 'company.rejected';

    public function __construct(
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private CompanyApprovalMailer $mailer,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private string $loginUrl,
    ) {
    }

    /** @throws CompanyNotFound */
    public function approve(Uuid $companyId, Uuid $operatorId): Company
    {
        $company = $this->companyOf($companyId);
        if ($company->isActive()) {
            return $company;
        }
        $from = $company->getStatus();
        $company->activate($this->clock->now());
        $this->companies->save($company);
        $this->record($company, self::APPROVED, $from, $operatorId);

        foreach ($this->memberships->ofCompany($company->getId()) as $membership) {
            if (Role::OWNER === $membership->getRole()->getName()) {
                $owner = $membership->getUser();
                $this->mailer->approved(new CompanyApprovedMail($owner->getEmail()->value, $company->getName(), $this->loginUrl, $owner->getLocale()));
            }
        }

        return $company;
    }

    /** @throws CompanyNotFound */
    public function reject(Uuid $companyId, Uuid $operatorId): Company
    {
        $company = $this->companyOf($companyId);
        if (Company::STATUS_SUSPENDED === $company->getStatus()) {
            return $company;
        }
        $from = $company->getStatus();
        $company->suspend($this->clock->now());
        $this->companies->save($company);
        $this->record($company, self::REJECTED, $from, $operatorId);

        return $company;
    }

    private function companyOf(Uuid $companyId): Company
    {
        return $this->companies->ofId($companyId) ?? throw new CompanyNotFound('No such company.');
    }

    private function record(Company $company, string $action, string $from, Uuid $operatorId): void
    {
        $this->audit->record(new AuditEntry(
            CreateCompany::ENTITY_TYPE,
            $company->getId(),
            $action,
            $operatorId,
            ['name' => $company->getName(), 'from' => $from, 'status' => $company->getStatus()],
            $company->getId(),
        ));
    }
}
