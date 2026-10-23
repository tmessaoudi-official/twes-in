<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Licensing\Application\ManageSubscriptions;
use App\Shared\Infrastructure\ApiPlatform\Payload\DeleteVersion;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Stops managing the company in the path: its terms are erased, the audit log names them, and its members get full
 * access again. The address names the version read (`?version=`), as a delete of an edited record does.
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

        $version = DeleteVersion::of($context);

        try {
            $this->manage->stop(CompanyPath::identifier($uriVariables, 'companyId'), $account->getId(), $version);
        } catch (CompanyNotFound $notFound) {
            throw new NotFoundHttpException('No such company.', $notFound);
        }

        return null;
    }
}
