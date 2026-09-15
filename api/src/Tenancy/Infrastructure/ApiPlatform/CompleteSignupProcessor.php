<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Invitation\PasswordBreached;
use App\Tenancy\Application\Signup\CompleteSignup;
use App\Tenancy\Application\Signup\CompleteSignupRequest;
use App\Tenancy\Application\Signup\InvalidSignup;
use App\Tenancy\Application\Signup\SignupNotUsable;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProcessorInterface<SignupResource, SignupResource> */
final readonly class CompleteSignupProcessor implements ProcessorInterface
{
    public function __construct(private CompleteSignup $complete)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SignupResource
    {
        $token = $uriVariables['token'] ?? null;
        if (!\is_string($token)) {
            throw new NotFoundHttpException('That signup link cannot be used.');
        }

        try {
            $outcome = $this->complete->handle(new CompleteSignupRequest($token, trim($data->displayName), $data->password, trim($data->companyName), $data->countryCode, $data->timezone));
        } catch (SignupNotUsable $unusable) {
            throw new NotFoundHttpException('That signup link cannot be used.', $unusable);
        } catch (InvalidSignup|PasswordBreached|CompanyNameTaken $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        $resource = new SignupResource();
        $resource->userId = $outcome->userId;
        $resource->companyName = $outcome->companyName;
        $resource->companyStatus = $outcome->companyStatus;

        return $resource;
    }
}
