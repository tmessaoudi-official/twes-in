<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Licensing\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Licensing\Application\CompanyStandings;
use App\Licensing\Application\ManageSubscriptions;
use App\Tenancy\Application\Company\CompanyNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The subscription of the company in the path; the operation's security has already required an operator.
 *
 * @implements ProviderInterface<PlatformSubscriptionResource>
 */
final readonly class PlatformSubscriptionProvider implements ProviderInterface
{
    public function __construct(private ManageSubscriptions $manage, private CompanyStandings $standings)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlatformSubscriptionResource
    {
        try {
            $subscription = $this->manage->ofCompany(CompanyPath::identifier($uriVariables, 'companyId'));
        } catch (CompanyNotFound $notFound) {
            throw new NotFoundHttpException('No such company.', $notFound);
        }
        if (null === $subscription) {
            throw new NotFoundHttpException('Licensing does not manage this company.');
        }

        return PlatformSubscriptionResource::of($subscription, $this->standings->standingOf($subscription));
    }
}
