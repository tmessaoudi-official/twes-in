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
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A platform operator opens a company. It starts pending, because a company with no owner cannot be signed
 * into; its first owner joining activates it (docs/SPEC.md § 7, 2026-09-09).
 */
final readonly class CreateCompany
{
    public const string ENTITY_TYPE = 'company';
    public const string CREATED = 'company.created';

    public function __construct(
        private CompanyRepository $companies,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @throws CompanyNameTaken */
    public function handle(NewCompany $request, ?Uuid $actorUserId): Company
    {
        if (null !== $this->companies->ofName($request->name)) {
            throw new CompanyNameTaken(\sprintf('A company named "%s" already exists.', $request->name));
        }

        $company = Company::pending(
            $request->name,
            $request->countryCode,
            $request->currency,
            $request->locale,
            $request->timezone,
            $this->clock->now(),
        );
        $this->companies->save($company);

        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $company->getId(),
            self::CREATED,
            $actorUserId,
            ['name' => $company->getName(), 'status' => $company->getStatus()],
            $company->getId(),
        ));

        return $company;
    }
}
