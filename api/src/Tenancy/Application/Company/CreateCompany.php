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
use App\Settings\Application\ChangeSettings;
use App\Settings\Application\SettingContext;
use App\Settings\Domain\ValueObject\SettingLevel;
use App\Shared\Application\Languages;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\CompanyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A platform operator opens a company. It starts pending, because a company with no owner cannot be signed
 * into; its first owner joining activates it (docs/SPEC.md § 7, 2026-09-09). It is opened with its fiscal
 * preset's taxes and units, so a country with no preset is refused before anything is written. Its documents print in
 * the language it is opened with, stored as its own setting, which it changes like any other.
 */
final readonly class CreateCompany
{
    public const string ENTITY_TYPE = 'company';
    public const string CREATED = 'company.created';

    public function __construct(
        private CompanyRepository $companies,
        private FiscalPresets $presets,
        private ProvisionCompany $provision,
        private ChangeSettings $settings,
        private Languages $languages,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
    ) {
    }

    /**
     * @throws CompanyNameTaken
     * @throws NoFiscalPreset
     */
    public function handle(NewCompany $request, ?Uuid $actorUserId): Company
    {
        return $this->transactions->run(function () use ($request, $actorUserId): Company {
            if (null !== $this->companies->ofName($request->name)) {
                throw new CompanyNameTaken(\sprintf('A company named "%s" already exists.', $request->name));
            }

            // A company's fiscal preset is its country's: read before the company exists, as its language may come from it.
            $preset = strtoupper($request->countryCode);
            if (!$this->presets->has($preset)) {
                throw new NoFiscalPreset(\sprintf('There is no fiscal preset for %s yet, so a company there could not invoice.', $preset));
            }
            $company = Company::pending(
                $request->name,
                $request->countryCode,
                $request->currency,
                $request->locale ?? $this->presets->get($preset)->documentLanguages[0] ?? throw new \LogicException(\sprintf('The %s preset names no language its documents print in.', $preset)),
                $request->timezone,
                $this->clock->now(),
            );
            $this->companies->save($company);
            $this->provision->handle($company);
            // A language the screens speak and documents do not leaves the installation's default in force.
            if (\in_array($company->getLocale(), $this->languages->documents(), true)) {
                $this->settings->change(new SettingContext($company), 'document.language', SettingLevel::Company, $company->getLocale(), $actorUserId);
            }

            $this->audit->record(new AuditEntry(
                self::ENTITY_TYPE,
                $company->getId(),
                self::CREATED,
                $actorUserId,
                ['name' => $company->getName(), 'status' => $company->getStatus(), 'fiscal_preset' => $company->getFiscalPreset()],
                $company->getId(),
            ));

            return $company;
        });
    }
}
