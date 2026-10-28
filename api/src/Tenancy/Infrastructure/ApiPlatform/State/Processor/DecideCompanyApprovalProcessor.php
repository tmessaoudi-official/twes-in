<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Identity\Infrastructure\Security\SecurityUser;
use App\Licensing\Application\CompanyStandings;
use App\Tenancy\Application\Approval\DecideCompanyApproval;
use App\Tenancy\Application\Approval\PlatformCompanies;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Domain\Exception\CompanyClosedDown;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\PlatformCompanyResource;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Approves or rejects the company in the path, a refusal with the reason the operator gave; the operation's security
 * has already required an operator.
 *
 * @implements ProcessorInterface<PlatformCompanyResource|null, PlatformCompanyResource>
 */
final readonly class DecideCompanyApprovalProcessor implements ProcessorInterface
{
    public function __construct(private DecideCompanyApproval $decide, private PlatformCompanies $companies, private Security $security, private CompanyStandings $standings)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlatformCompanyResource
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');

        try {
            $company = PlatformCompanyResource::REJECT === $operation->getName()
                ? $this->decide->reject($companyId, $account->getId(), $data instanceof PlatformCompanyResource ? $data->reason : null)
                : $this->decide->approve($companyId, $account->getId());
        } catch (CompanyNotFound $notFound) {
            throw new NotFoundHttpException($notFound->getMessage(), $notFound);
        } catch (CompanyClosedDown $closed) {
            // Its owner closed it down: only they take that back, and an operator's decision is no longer asked for.
            throw new ConflictHttpException($closed->getMessage(), $closed);
        }

        return PlatformCompanyResource::of($this->companies->viewOf($company), $this->standings->of($company->getId()));
    }
}
