<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\StepUp\RecentProof;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Tenancy\Application\Company\InvalidCompanyProfile;
use App\Tenancy\Application\Company\ReviseCompanyProfile;
use App\Tenancy\Infrastructure\ApiPlatform\Output\CompanyProfileRepresentation;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/** @implements ProcessorInterface<CompanyProfileResource, CompanyProfileResource> */
final readonly class ReviseCompanyProfileProcessor implements ProcessorInterface
{
    public function __construct(
        private ReviseCompanyProfile $revise,
        private CompanyGuard $guard,
        private RecentProof $proof,
        private CompanyProfileRepresentation $representation,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanyProfileResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);
        $profile = $data->toProfile();
        // Only the bank details are on the step-up list; the rest of the profile is revised as before.
        if ([] !== array_intersect(['iban', 'bic'], $profile->differencesFrom($company->getProfile()))) {
            $this->proof->demand($this->guard->account()->getId());
        }

        try {
            $this->revise->handle($company, $profile, $this->guard->account()->getId());
        } catch (InvalidCompanyProfile $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), $refused->reason, $refused->params, $refused);
        }

        return $this->representation->of($company);
    }
}
