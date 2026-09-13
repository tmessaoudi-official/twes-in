<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\Company\ProvisionCompany;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A platform operator opens a company. It starts pending, because a company with no owner cannot be signed
 * into; its first owner joining activates it (docs/SPEC.md § 7, 2026-09-09). It is opened with its fiscal
 * preset's taxes and units, so a country with no preset is refused before anything is written.
 */
final readonly class CreateCompany
{
    public const string ENTITY_TYPE = 'company';
    public const string CREATED = 'company.created';

    public function __construct(
        private CompanyRepository $companies,
        private FiscalPresets $presets,
        private ProvisionCompany $provision,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws CompanyNameTaken
     * @throws NoFiscalPreset
     */
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
        if (!$this->presets->has($company->getFiscalPreset())) {
            throw new NoFiscalPreset(\sprintf('There is no fiscal preset for %s yet, so a company there could not invoice.', $company->getCountryCode()));
        }
        $this->companies->save($company);
        $this->provision->handle($company);

        $this->audit->record(new AuditEntry(
            self::ENTITY_TYPE,
            $company->getId(),
            self::CREATED,
            $actorUserId,
            ['name' => $company->getName(), 'status' => $company->getStatus(), 'fiscal_preset' => $company->getFiscalPreset()],
            $company->getId(),
        ));

        return $company;
    }
}
