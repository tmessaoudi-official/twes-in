<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\KeepLossAttachments;
use App\Module\Inventory\Application\StockLossAttachmentNotFound;
use App\Module\Inventory\Application\StockMovementNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<StockLossAttachmentResource, null> */
final readonly class DetachStockLossAttachmentProcessor implements ProcessorInterface
{
    public function __construct(private KeepLossAttachments $attachments, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);

        try {
            $this->attachments->detach($company, CompanyPath::identifier($uriVariables, 'movementId'), CompanyPath::identifier($uriVariables, 'attachmentId'), $this->guard->account()->getId());
        } catch (StockMovementNotFound|StockLossAttachmentNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}
