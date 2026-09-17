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
use App\Licensing\Application\CompanyStandings;
use App\Licensing\Application\ManageSubscriptions;
use App\Licensing\Domain\InvalidSubscription;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Domain\CompanyRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Puts terms on the company in the path, starting to manage it or revising what it holds.
 *
 * @implements ProcessorInterface<PlatformSubscriptionResource, PlatformSubscriptionResource>
 */
final readonly class SetSubscriptionProcessor implements ProcessorInterface
{
    public function __construct(
        private ManageSubscriptions $manage,
        private CompanyStandings $standings,
        private CompanyRepository $companies,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PlatformSubscriptionResource
    {
        $account = $this->security->getUser();
        if (!$account instanceof SecurityUser) {
            throw new AccessDeniedException();
        }
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        $company = $this->companies->ofId($companyId) ?? throw new NotFoundHttpException('No such company.');

        try {
            $subscription = $this->manage->set($companyId, $data->terms($company->getTimezone()), $account->getId());
        } catch (CompanyNotFound $notFound) {
            throw new NotFoundHttpException('No such company.', $notFound);
        } catch (InvalidSubscription $refused) {
            throw new UnprocessableEntityHttpException($refused->getMessage(), $refused);
        }

        return PlatformSubscriptionResource::of($subscription, $this->standings->standingOf($subscription));
    }
}
