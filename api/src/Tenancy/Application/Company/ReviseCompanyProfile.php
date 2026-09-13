<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Tenancy\Domain\Company;
use App\Tenancy\Domain\CompanyProfile;
use App\Tenancy\Domain\CompanyRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company revises what its documents say about it. Its fiscal preset decides what that may be: a registration number
 * the preset does not know is refused, one it knows must have its shape, one it requires of a company must be there,
 * and the VAT regime must be one the preset offers companies. A revision is audited with the names of the fields it
 * changed, not their values, so banking details never reach the audit trail.
 */
final readonly class ReviseCompanyProfile
{
    public const string REVISED = 'company.profile_revised';

    public function __construct(
        private CompanyRepository $companies,
        private FiscalPresets $presets,
        private AuditTrail $audit,
        private ClockInterface $clock,
    ) {
    }

    /** @throws InvalidCompanyProfile */
    public function handle(Company $company, CompanyProfile $profile, ?Uuid $actorUserId): Company
    {
        $preset = $this->presets->get($company->getFiscalPreset());

        if (!\in_array($profile->vatRegime, array_map(static fn ($regime): string => $regime->code, $preset->companyVatRegimes), true)) {
            throw new InvalidCompanyProfile('vatRegime', \sprintf('The %s preset offers companies no VAT regime "%s".', $preset->country, $profile->vatRegime));
        }

        $known = [];
        foreach ($preset->identifiers as $identifier) {
            $known[] = $identifier->key;
            $value = $profile->identifiers[$identifier->key] ?? null;
            if (null === $value) {
                if (\in_array('company', $identifier->requiredFor, true)) {
                    throw new InvalidCompanyProfile("identifiers.$identifier->key", \sprintf('The %s preset requires a company to carry its %s.', $preset->country, $identifier->key));
                }
                continue;
            }
            if (1 !== preg_match('#'.str_replace('#', '\#', $identifier->pattern).'#u', $value)) {
                throw new InvalidCompanyProfile("identifiers.$identifier->key", \sprintf('This %s does not have the shape the %s preset expects.', $identifier->key, $preset->country));
            }
        }
        foreach (array_keys($profile->identifiers) as $key) {
            if (!\in_array($key, $known, true)) {
                throw new InvalidCompanyProfile("identifiers.$key", \sprintf('The %s preset knows no identifier "%s".', $preset->country, $key));
            }
        }

        $changed = $profile->differencesFrom($company->getProfile());
        if ($company->reviseProfile($profile, $this->clock->now())) {
            $this->companies->save($company);
            $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::REVISED, $actorUserId, ['fields' => $changed], $company->getId()));
        }

        return $company;
    }
}
