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
use App\Licensing\Domain\PaymentDeclarationRepository;
use App\Licensing\Domain\SubscriptionRepository;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<CompanySubscriptionResource> */
final readonly class CompanySubscriptionProvider implements ProviderInterface
{
    public function __construct(
        private CompanyGuard $guard,
        private SubscriptionRepository $subscriptions,
        private PaymentDeclarationRepository $declarations,
        private CompanyStandings $standings,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CompanySubscriptionResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanySubscriptionResource::READ_PERMISSION);
        $subscription = $this->subscriptions->ofCompany($company->getId())
            ?? throw new NotFoundHttpException('Licensing does not manage this company.');

        return CompanySubscriptionResource::of(
            $subscription,
            $this->standings->standingOf($subscription),
            $this->declarations->openOfCompany($company->getId()),
            $this->declarations->ofCompany($company->getId(), CompanySubscriptionResource::HISTORY),
            $this->guard->may($company, CompanySubscriptionResource::PAY_PERMISSION),
        );
    }
}
