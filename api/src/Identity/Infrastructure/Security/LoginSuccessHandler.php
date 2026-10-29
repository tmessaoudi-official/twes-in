<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Application\CompanyProof\CompanyEntryProofs;
use App\Identity\Application\Login\SignInFactors;
use App\Identity\Domain\Repository\UserRepository;
use App\Identity\Infrastructure\ApiPlatform\Output\MeFactory;
use App\Tenancy\Application\Session\ChooseWorkingCompany;
use App\Tenancy\Application\Session\CompanyEntry;
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
    /** The request attribute naming what the sign-in was proved with, set by whatever completed it; a password otherwise. */
    public const string FACTORS = '_sign_in_factors';

    public function __construct(
        private ChooseWorkingCompany $chooseWorkingCompany,
        private MeFactory $me,
        private SerializerInterface $serializer,
        private UserRepository $users,
        private CompanyEntryProofs $entryProofs,
        private CompanyEntry $entry,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $account = $token->getUser();
        if (!$account instanceof SecurityUser) {
            throw new \LogicException('The main firewall authenticates SecurityUser only.');
        }
        $this->entry->signedIn();
        $opened = $this->chooseWorkingCompany->for($account->getId());
        // The company the sign-in opens is proved by the sign-in itself when it was made with what that company takes;
        // otherwise it asks its own proof as any other company does.
        $factors = $request->attributes->get(self::FACTORS);
        $user = null === $opened ? null : $this->users->ofId($account->getId());
        if (null !== $opened && null !== $user && $this->entryProofs->satisfiedBy($factors instanceof SignInFactors ? $factors : SignInFactors::Password, $user, $opened->getCompany())) {
            $this->entry->proved($opened->getCompany()->getId());
        }

        return new JsonResponse($this->serializer->serialize($this->me->for($account), 'json'), Response::HTTP_OK, [], true);
    }
}
