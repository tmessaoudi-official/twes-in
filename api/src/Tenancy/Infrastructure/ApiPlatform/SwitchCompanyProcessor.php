<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\NotAMember;
use App\Tenancy\Application\Session\DescribeWorkingContext;
use App\Tenancy\Application\Session\SwitchWorkingCompany;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<WorkingCompanyResource, WorkingCompanyResource> */
final readonly class SwitchCompanyProcessor implements ProcessorInterface
{
    public function __construct(
        private SwitchWorkingCompany $switchCompany,
        private DescribeWorkingContext $describe,
        private CompanyGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WorkingCompanyResource
    {
        $account = $this->guard->account();
        try {
            $this->switchCompany->to($account->getId(), Uuid::fromString($data->companyId));
        } catch (NotAMember $refused) {
            // Not "forbidden": a company the user has nothing to do with must look exactly like one that is not there.
            throw new NotFoundHttpException('No such company.', $refused);
        }

        $working = $this->describe->for($account->getId())
            ?? throw new NotFoundHttpException('No such company.');

        $resource = new WorkingCompanyResource();
        $resource->companyId = $working->companyId;
        $resource->name = $working->name;
        $resource->status = $working->status;
        $resource->role = $working->role;

        return $resource;
    }
}
