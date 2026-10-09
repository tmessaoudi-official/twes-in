<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\KeepLossAttachments;
use App\Module\Inventory\Application\StockMovementNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<StockLossAttachmentResource> */
final readonly class StockLossAttachmentCollectionProvider implements ProviderInterface
{
    public function __construct(private KeepLossAttachments $attachments, private CompanyGuard $guard)
    {
    }

    /** @return list<StockLossAttachmentResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);

        try {
            return array_map(StockLossAttachmentResource::of(...), $this->attachments->attachments($company, CompanyPath::identifier($uriVariables, 'movementId')));
        } catch (StockMovementNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }
    }
}
