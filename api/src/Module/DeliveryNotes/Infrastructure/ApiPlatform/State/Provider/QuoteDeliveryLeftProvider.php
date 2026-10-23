<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\DeliveryNotes\Application\Quotes\DeliveredQuoteNotFound;
use App\Module\DeliveryNotes\Application\Quotes\DeliverQuotes;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\QuoteDeliveryLeftResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Module\Quotes\Infrastructure\Module\QuotesModule;
use App\ModuleRegistry\Application\ModuleStates;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<QuoteDeliveryLeftResource> */
final readonly class QuoteDeliveryLeftProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private DeliverQuotes $delivering, private ModuleStates $modules)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): QuoteDeliveryLeftResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::WRITE);
        // The module guard answered for delivery notes, whose resource this is; the quote it reads needs quotes on too.
        if (!$this->modules->isEnabled($company->getId(), QuotesModule::KEY)) {
            throw new NotFoundHttpException('No such company.');
        }
        $quoteId = CompanyPath::identifier($uriVariables, 'quoteId');

        try {
            return QuoteDeliveryLeftResource::of($quoteId->toRfc4122(), $this->delivering->left($company, $quoteId));
        } catch (DeliveredQuoteNotFound $absent) {
            throw new NotFoundHttpException('No such quote.', $absent);
        }
    }
}
