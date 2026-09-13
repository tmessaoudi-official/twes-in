<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\InvalidCompanyProfile;
use App\Tenancy\Application\Company\ReviseCompanyProfile;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<CompanyProfileResource, CompanyProfileResource> */
final readonly class ReviseCompanyProfileProcessor implements ProcessorInterface
{
    public function __construct(
        private ReviseCompanyProfile $revise,
        private CompanyGuard $guard,
        private CompanyProfileRepresentation $representation,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanyProfileResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::WRITE_PERMISSION);

        try {
            $this->revise->handle($company, $data->toProfile(), $this->guard->account()->getId());
        } catch (InvalidCompanyProfile $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return $this->representation->of($company);
    }
}
