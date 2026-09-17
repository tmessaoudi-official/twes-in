<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Licensing\Application\ManageSubscriptions;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Stops managing the company in the path: its terms are forgotten, the audit log keeps them, and its members get full
 * access again.
 *
 * @implements ProcessorInterface<mixed, null>
 */
final readonly class StopSubscriptionProcessor implements ProcessorInterface
{
    public function __construct(private ManageSubscriptions $manage, private Security $security)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }

        try {
            $this->manage->stop(CompanyPath::identifier($uriVariables, 'companyId'), $account->getId());
        } catch (CompanyNotFound $notFound) {
            throw new NotFoundHttpException('No such company.', $notFound);
        }

        return null;
    }
}
