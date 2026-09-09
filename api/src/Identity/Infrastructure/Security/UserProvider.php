<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Security;

use App\Identity\Domain\Email;
use App\Identity\Domain\UserRepository;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads the security snapshot through the repository port: by email at login, by id on every later request.
 * Upgrades a hash whose parameters fell behind security.yaml, as Symfony asks a provider to (PasswordUpgraderInterface).
 *
 * @implements UserProviderInterface<SecurityUser>
 */
final readonly class UserProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(private UserRepository $users)
    {
    }

    public function loadUserByIdentifier(string $identifier): SecurityUser
    {
        try {
            $email = Email::fromString($identifier);
        } catch (\InvalidArgumentException) {
            throw $this->notFound($identifier);
        }
        $user = $this->users->ofEmail($email) ?? throw $this->notFound($identifier);

        return SecurityUser::of($user);
    }

    public function refreshUser(UserInterface $user): SecurityUser
    {
        if (!$user instanceof SecurityUser) {
            throw new UnsupportedUserException(\sprintf('Instances of "%s" are not supported.', $user::class));
        }
        $fresh = $this->users->ofId($user->getId()) ?? throw $this->notFound($user->getUserIdentifier());

        return SecurityUser::of($fresh);
    }

    public function supportsClass(string $class): bool
    {
        return SecurityUser::class === $class;
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof SecurityUser) {
            return;
        }
        $account = $this->users->ofId($user->getId());
        if (null === $account) {
            return;
        }
        $account->upgradePasswordHash($newHashedPassword);
        $this->users->save($account);
    }

    private function notFound(string $identifier): UserNotFoundException
    {
        $exception = new UserNotFoundException();
        $exception->setUserIdentifier($identifier);

        return $exception;
    }
}
