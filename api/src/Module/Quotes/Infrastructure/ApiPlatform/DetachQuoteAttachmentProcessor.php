<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Quotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Quotes\Application\ManageQuotes;
use App\Module\Quotes\Application\QuoteAttachmentNotFound;
use App\Module\Quotes\Application\QuoteNotFound;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProcessorInterface<QuoteAttachmentResource, null> */
final readonly class DetachQuoteAttachmentProcessor implements ProcessorInterface
{
    public function __construct(private ManageQuotes $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), QuotePermission::WRITE);

        try {
            $this->manage->detach($company, CompanyPath::identifier($uriVariables, 'quoteId'), CompanyPath::identifier($uriVariables, 'attachmentId'), $this->guard->account()->getId());
        } catch (QuoteNotFound|QuoteAttachmentNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        }

        return null;
    }
}
