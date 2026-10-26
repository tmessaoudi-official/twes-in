<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Application\Company;

use App\Audit\Application\AuditEntry;
use App\Audit\Application\AuditTrail;
use App\Audit\Application\DeclaresAuditActions;
use App\Audit\Domain\ValueObject\AuditScope;
use App\Audit\Domain\ValueObject\Recorded;
use App\Fiscal\Application\Preset\FiscalPresets;
use App\Fiscal\Application\Preset\IdentifierRules;
use App\Shared\Application\RecordChanged;
use App\Shared\Application\RecordVersions;
use App\Shared\Application\Transactions;
use App\Tenancy\Domain\Model\Company;
use App\Tenancy\Domain\Repository\CompanyRepository;
use App\Tenancy\Domain\ValueObject\CompanyProfile;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * A company revises what its documents say about it. Its fiscal preset decides what that may be: a registration number
 * the preset does not know is refused, one it knows must have its shape, one it requires of a company must be there,
 * the VAT regime must be one the preset offers companies, and only a preset offering the débits option lets a company
 * opt for it. A revision is audited with the names of the fields it
 * changed, not their values, so banking details never reach the audit trail.
 */
final readonly class ReviseCompanyProfile implements DeclaresAuditActions
{
    public const string REVISED = 'company.profile_revised';

    public function __construct(
        private CompanyRepository $companies,
        private FiscalPresets $presets,
        private AuditTrail $audit,
        private ClockInterface $clock,
        private Transactions $transactions,
        private RecordVersions $versions,
    ) {
    }

    /** @throws InvalidCompanyProfile|RecordChanged */
    public function handle(Company $company, CompanyProfile $profile, ?Uuid $actorUserId, ?int $version = null): Company
    {
        $preset = $this->presets->get($company->getFiscalPreset());
        $profile = $profile->withIdentifiers(IdentifierRules::normalised($preset, $profile->identifiers, IdentifierRules::COMPANY));

        if (!\in_array($profile->vatRegime, array_map(static fn ($regime): string => $regime->code, $preset->companyVatRegimes), true)) {
            throw new InvalidCompanyProfile('vatRegime', \sprintf('The %s preset offers companies no VAT regime "%s".', $preset->country, $profile->vatRegime), 'unknown_tax_regime', ['code' => $profile->vatRegime]);
        }

        if ($profile->vatOnDebits && !$preset->invoiceFields->offersVatOnDebits()) {
            throw new InvalidCompanyProfile('vatOnDebits', \sprintf('The %s preset offers companies no option to pay VAT on the débits.', $preset->country), 'unknown_choice');
        }

        $refusal = IdentifierRules::refusal($preset, $profile->identifiers, IdentifierRules::COMPANY);
        if (null !== $refusal) {
            throw new InvalidCompanyProfile($refusal->field, $refusal->message, $refusal->reason, $refusal->params);
        }

        return $this->transactions->run(function () use ($company, $profile, $actorUserId, $version): Company {
            $company = $this->companies->lockedOfId($company->getId()) ?? throw new CompanyNotFound('No such company.');
            if (null !== $version) {
                $this->versions->expect($company, $version);
            }
            $changed = $profile->differencesFrom($company->getProfile());
            if ($company->reviseProfile($profile, $this->clock->now())) {
                $this->companies->save($company);
                $this->audit->record(new AuditEntry(CreateCompany::ENTITY_TYPE, $company->getId(), self::REVISED, $actorUserId, ['fields' => $changed], $company->getId()));
            }

            return $company;
        });
    }

    public function auditActions(): array
    {
        return [
            self::REVISED => new Recorded(CreateCompany::ENTITY_TYPE, AuditScope::Company),
        ];
    }
}
