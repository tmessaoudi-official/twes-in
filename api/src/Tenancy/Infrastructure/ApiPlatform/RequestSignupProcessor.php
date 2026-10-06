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
use App\Tenancy\Application\Signup\SignupPolicy;
use App\Tenancy\Infrastructure\Signup\SignupAsked;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Asks for a signup link. Two budgets (config/packages/rate_limiter.yaml): one per client, which answers 429 because
 * saying "slow down" tells nobody anything about an address; and one per address, which stops the mailing and still
 * answers 202, because a different answer for a second ask would say the first one was taken seriously. What it accepts
 * is queued for the worker, the same for every address, so the time the answer takes says nothing either.
 *
 * @implements ProcessorInterface<SignupResource, null>
 */
final readonly class RequestSignupProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private SignupPolicy $policy,
        #[Target('signup_client')]
        private RateLimiterFactoryInterface $signupClientLimiter,
        #[Target('signup_address')]
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

        $this->bus->dispatch(new SignupAsked($email->value, $data->locale));

        return null;
    }
}
