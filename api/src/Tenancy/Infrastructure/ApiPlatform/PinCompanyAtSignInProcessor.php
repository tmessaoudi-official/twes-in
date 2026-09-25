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
use App\Tenancy\Application\Session\PinCompanyAtSignIn;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<CompanyAtSignInResource, CompanyAtSignInResource> */
final readonly class PinCompanyAtSignInProcessor implements ProcessorInterface
{
    public function __construct(private PinCompanyAtSignIn $pin, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CompanyAtSignInResource
    {
        try {
            $this->pin->to($this->guard->account()->getId(), null === $data->companyId ? null : Uuid::fromString($data->companyId));
        } catch (NotAMember $refused) {
            // As the switcher answers: a company the person has nothing to do with looks like one that is not there.
            throw new NotFoundHttpException('No such company.', $refused);
        }

        return $data;
    }
}
