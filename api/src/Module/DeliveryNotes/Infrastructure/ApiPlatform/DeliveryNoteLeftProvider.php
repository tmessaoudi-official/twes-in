<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Application\InvoiceDeliveryNotes;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @implements ProviderInterface<DeliveryNoteLeftResource> */
final readonly class DeliveryNoteLeftProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private InvoiceDeliveryNotes $invoicing)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteLeftResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::WRITE);
        $noteId = CompanyPath::identifier($uriVariables, 'deliveryNoteId');

        try {
            return DeliveryNoteLeftResource::of($noteId->toRfc4122(), $this->invoicing->left($company, $noteId));
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        }
    }
}
