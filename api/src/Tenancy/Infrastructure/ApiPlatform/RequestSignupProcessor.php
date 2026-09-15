<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Domain\Email;
use App\Tenancy\Application\Signup\RequestSignup;
use App\Tenancy\Application\Signup\SignupClosed;
use App\Tenancy\Application\Signup\SignupPolicy;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Asks for a signup link. Two budgets (config/packages/rate_limiter.yaml): one per client, which answers 429 because
 * saying "slow down" tells nobody anything about an address; and one per address, which stops the mailing and still
 * answers 202, because a different answer for a second ask would say the first one was taken seriously.
 *
 * @implements ProcessorInterface<SignupResource, null>
 */
final readonly class RequestSignupProcessor implements ProcessorInterface
{
    public function __construct(
        private RequestSignup $requestSignup,
        private SignupPolicy $policy,
        private RateLimiterFactoryInterface $signupClientLimiter,
        private RateLimiterFactoryInterface $signupAddressLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        if (!$this->policy->isOpen()) {
            throw new NotFoundHttpException('Signup is closed.');
        }

        $request = $context['request'] ?? null;
        $client = $request instanceof Request ? ($request->getClientIp() ?? '') : '';
        if (!$this->signupClientLimiter->create($client)->consume()->isAccepted()) {
            throw new TooManyRequestsHttpException(null, 'Too many signup requests from here; try again later.');
        }

        try {
            $email = Email::fromString($data->email);
        } catch (\InvalidArgumentException $malformed) {
            throw new UnprocessableEntityHttpException('email: that is not an email address.', $malformed);
        }

        if (!$this->signupAddressLimiter->create(hash('sha256', $email->value))->consume()->isAccepted()) {
            return null;
        }

        try {
            $this->requestSignup->handle($email, $data->locale);
        } catch (SignupClosed $closed) {
            throw new NotFoundHttpException('Signup is closed.', $closed);
        }

        return null;
    }
}
