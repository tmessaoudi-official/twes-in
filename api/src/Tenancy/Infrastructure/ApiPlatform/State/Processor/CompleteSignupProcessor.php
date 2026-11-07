<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Shared\Domain\Service\TypedText;
use App\Shared\Infrastructure\ApiPlatform\Output\DomainRule;
use App\Shared\Infrastructure\Http\SpentBudget;
use App\Tenancy\Application\Company\CompanyNameTaken;
use App\Tenancy\Application\Invitation\PasswordBreached;
use App\Tenancy\Application\Signup\CompleteSignup;
use App\Tenancy\Application\Signup\CompleteSignupRequest;
use App\Tenancy\Application\Signup\InvalidSignup;
use App\Tenancy\Application\Signup\SignupNotUsable;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\SignupResource;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/** @implements ProcessorInterface<SignupResource, SignupResource> */
final readonly class CompleteSignupProcessor implements ProcessorInterface
{
    public function __construct(
        private CompleteSignup $complete,
        private RequestStack $requests,
        #[Target('signup_complete')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SignupResource
    {
        // Before the link is even looked up: a refused finish leaves it usable, and each try would say whether a name is taken.
        $limit = $this->limiter->create($this->requests->getMainRequest()?->getClientIp() ?? '')->consume();
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(SpentBudget::secondsUntil($limit), 'Too many attempts.');
        }
        $token = $uriVariables['token'] ?? null;
        if (!\is_string($token)) {
            throw new NotFoundHttpException('That signup link cannot be used.');
        }

        try {
            $outcome = $this->complete->handle(new CompleteSignupRequest($token, TypedText::trimmed($data->displayName), $data->password, TypedText::trimmed($data->companyName), $data->countryCode, $data->timezone));
        } catch (SignupNotUsable $unusable) {
            throw new NotFoundHttpException('That signup link cannot be used.', $unusable);
        } catch (InvalidSignup $refused) {
            throw DomainRule::refused($refused->field, $refused->getMessage(), 'invalid_value', [], $refused);
        } catch (PasswordBreached $breached) {
            throw DomainRule::refused('password', $breached->getMessage(), 'breached', [], $breached);
        } catch (CompanyNameTaken $taken) {
            throw DomainRule::refused('companyName', $taken->getMessage(), 'company_name_taken', [], $taken);
        }

        $resource = new SignupResource();
        $resource->userId = $outcome->userId;
        $resource->companyName = $outcome->companyName;
        $resource->companyStatus = $outcome->companyStatus;

        return $resource;
    }
}
