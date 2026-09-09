<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Infrastructure\ApiPlatform\MeFactory;
use App\Tenancy\Application\Session\ChooseWorkingCompany;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * A successful login answers 200 with the same body as GET /api/auth/me. The session company is chosen here,
 * before the body is built and before LoginSuccessEvent (which Symfony dispatches only after this handler returns).
 */
final readonly class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private ChooseWorkingCompany $chooseWorkingCompany, private MeFactory $me, private SerializerInterface $serializer)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $account = $token->getUser();
        if (!$account instanceof SecurityUser) {
            throw new \LogicException('The main firewall authenticates SecurityUser only.');
        }
        $this->chooseWorkingCompany->for($account->getId());

        return new JsonResponse($this->serializer->serialize($this->me->for($account), 'json'), Response::HTTP_OK, [], true);
    }
}
