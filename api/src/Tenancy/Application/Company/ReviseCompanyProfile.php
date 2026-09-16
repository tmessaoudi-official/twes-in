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
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Shared\Application\Transactions;
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
        private Transactions $transactions,
    ) {
    }

    /** @throws InvalidCompanyProfile */
    public function handle(Company $company, CompanyProfile $profile, ?Uuid $actorUserId): Company
    {
        $preset = $this->presets->get($company->getFiscalPreset());

        if (!\in_array($profile->vatRegime, array_map(static fn ($regime): string => $regime->code, $preset->companyVatRegimes), true)) {
            throw new InvalidCompanyProfile('vatRegime', \sprintf('The %s preset offers companies no VAT regime "%s".', $preset->country, $profile->vatRegime));
        }

        $refusal = IdentifierRules::refusal($preset, $profile->identifiers, IdentifierRules::COMPANY);
        if (null !== $refusal) {
            throw new InvalidCompanyProfile(...$refusal);
        }

        return $this->transactions->run(function () use ($company, $profile, $actorUserId): Company {
            $changed = $profile->differencesFrom($company->getProfile());
            if ($company->reviseProfile($profile, $this->clock->now())) {
                $this->companies->save($company);
                $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::REVISED, $actorUserId, ['fields' => $changed], $company->getId()));
            }

            return $company;
        });
    }
}
