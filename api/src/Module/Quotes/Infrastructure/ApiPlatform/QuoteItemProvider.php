<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<QuoteResource> */
final readonly class QuoteItemProvider implements ProviderInterface
{
    public function __construct(private ManageQuotes $manage, private QuoteView $view, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): QuoteResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::READ);

        try {
            return $this->view->of($this->manage->get($company, CompanyPath::identifier($uriVariables, 'quoteId')));
        } catch (QuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        }
    }
}
