<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Application\Account\AccountNotFound;
use App\Identity\Application\Account\ManageAccounts;
use App\Identity\Application\Account\OwnAccount;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Uid\Uuid;

/**
 * Ends the sessions of, deactivates or reactivates the account in the path; the operation's security has already
 * required an operator.
 *
 * @implements ProcessorInterface<null, PlatformAccountResource>
 */
final readonly class ManageAccountProcessor implements ProcessorInterface
{
    public function __construct(private ManageAccounts $accounts, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlatformAccountResource
    {
        $operator = $this->security->getUser();
        if (!$operator instanceof SecurityUser) {
            throw new AccessDeniedException();
        }
        $raw = $uriVariables['userId'] ?? null;
        if (!\is_string($raw) || !Uuid::isValid($raw)) {
            throw new NotFoundHttpException('No such account.');
        }
        $userId = Uuid::fromString($raw);

        try {
            $view = match ($operation->getName()) {
                PlatformAccountResource::DEACTIVATE => $this->accounts->deactivate($userId, $operator->getId()),
                PlatformAccountResource::REACTIVATE => $this->accounts->reactivate($userId, $operator->getId()),
                default => $this->accounts->endSessions($userId, $operator->getId()),
            };
        } catch (AccountNotFound $notFound) {
            throw new NotFoundHttpException($notFound->getMessage(), $notFound);
        } catch (OwnAccount $own) {
            throw new ConflictHttpException($own->getMessage(), $own);
        }

        return PlatformAccountResource::of($view);
    }
}
