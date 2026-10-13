<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Identity\Infrastructure\Account;

use App\Identity\Application\Account\DeleteOwnAccount;
use App\Identity\Application\Account\OperatorAccount;
use App\Identity\Application\Account\OwnedAlone;
use App\Identity\Application\Account\SoleOwner;
use App\Identity\Application\StepUp\StepUpRequired;
use App\Identity\Infrastructure\Security\SecurityUser;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A signed-in person asks for their own account to be deleted (DeleteOwnAccount). The answer says when it becomes
 * final, and this session ends with it, as every other one of the account already has.
 */
final readonly class AccountDeletionController
{
    public function __construct(private Security $security, private DeleteOwnAccount $delete)
    {
    }

    #[Route('/api/auth/account', name: 'api_auth_account_delete', methods: ['DELETE'])]
    public function __invoke(): Response
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new \LogicException('The account endpoint runs behind the firewall.');
        }

        try {
            $finalAt = $this->delete->request($account->getId());
        } catch (StepUpRequired) {
            return new JsonResponse(['error' => 'step_up_required'], Response::HTTP_FORBIDDEN);
        } catch (OperatorAccount) {
            return new JsonResponse(['error' => 'platform_operator'], Response::HTTP_CONFLICT);
        } catch (SoleOwner $sole) {
            return new JsonResponse([
                'error' => 'sole_owner',
                'companies' => array_map(static fn (OwnedAlone $company): array => ['id' => $company->companyId->toRfc4122(), 'name' => $company->name], $sole->companies),
            ], Response::HTTP_CONFLICT);
        }

        $this->security->logout(false);

        return new JsonResponse(['finalAt' => $finalAt->format(\DATE_ATOM)]);
    }
}
