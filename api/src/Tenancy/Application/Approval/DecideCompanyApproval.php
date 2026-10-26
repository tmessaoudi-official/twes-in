<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Approval;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Shared\Application\Transactions;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Application\Company\CreateCompany;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Model\Role;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\Repository\MembershipRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * An operator's decision on a company (docs/SPEC.md § 7, 2026-09-15). Approving opens one that waits or was suspended to
 * its members and tells its owners; rejecting suspends one that waits, and suspends an open one, recorded as a
 * suspension. A decision the company already carries changes nothing: no second mail, no second audit entry.
 */
final readonly class DecideCompanyApproval implements DeclaresAuditActions
{
    public const string APPROVED = 'company.approved';
    public const string REJECTED = 'company.rejected';
    /** An open company closed by an operator, whose suspend stays available at any time (docs/SPEC.md § 7, 2026-09-17). */
    public const string SUSPENDED = 'company.suspended';

    public function __construct(
        private CompanyRepository $companies,
        private MembershipRepository $memberships,
        private CompanyApprovalMailer $mailer,
        private AuditTrail $audit,
        private ClockInterface $clock,
        #[Autowire(param: 'app.signup.login_url')]
        private string $loginUrl,
        private Transactions $transactions,
    ) {
    }

    /** @throws CompanyNotFound */
    public function approve(Uuid $companyId, Uuid $operatorId): Company
    {
        // Decided on the row held, never on an earlier read: a suspension saved in between is what is approved.
        [$company, $approved] = $this->transactions->run(function () use ($companyId, $operatorId): array {
            $company = $this->heldCompany($companyId);
            if ($company->isActive()) {
                return [$company, false];
            }
            $from = $company->getStatus();
            $company->activate($this->clock->now());
            $this->companies->save($company);
            $this->record($company, self::APPROVED, $from, $operatorId);

            return [$company, true];
        });
        if (!$approved) {
            return $company;
        }

        // The owners hear of it once the approval is stored, never of one that rolled back.
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
        return $this->transactions->run(function () use ($companyId, $operatorId): Company {
            $company = $this->heldCompany($companyId);
            if (Company::STATUS_SUSPENDED === $company->getStatus()) {
                return $company;
            }
            $from = $company->getStatus();
            $company->suspend($this->clock->now());
            $this->companies->save($company);
            $this->record($company, Company::STATUS_ACTIVE === $from ? self::SUSPENDED : self::REJECTED, $from, $operatorId);

            return $company;
        });
    }

    private function heldCompany(Uuid $companyId): Company
    {
        return $this->companies->lockedOfId($companyId) ?? throw new CompanyNotFound('No such company.');
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

    public function auditActions(): array
    {
        return [
            self::APPROVED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
            self::REJECTED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
            self::SUSPENDED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
