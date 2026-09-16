<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company requires, or stops requiring, a second factor of its members (docs/SPEC.md § 3 Auth). The requirement is
 * read across every membership an account holds, so switching it on holds back each member without a factor until
 * they enrol, whichever company their session works in. Only a change is saved and audited.
 */
final readonly class RequireSecondFactor
{
    public const string CHANGED = 'company.mfa_required_changed';

    public function __construct(
        private CompanyRepository $companies,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    public function handle(Company $company, bool $required, ?Uuid $actorUserId): Company
    {
        return $this->transactions->run(function () use ($company, $required, $actorUserId): Company {
            if ($company->requireMfa($required, $this->clock->now())) {
                $this->companies->save($company);
                $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::CHANGED, $actorUserId, ['required' => $required], $company->getId()));
            }

            return $company;
        });
    }
}
