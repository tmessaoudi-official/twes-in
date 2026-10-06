<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Infrastructure\ApiPlatform\CustomerPermission;
use App\Module\DeliveryNotes\Application\DeliveryNoteCredit;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Domain\InvalidDeliveryNote;
use App\Module\Invoices\Infrastructure\ApiPlatform\InvoicePermission;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/** @implements ProviderInterface<DeliveryNoteCreditResource> */
final readonly class DeliveryNoteCreditProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private DeliveryNoteCredit $credit)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): DeliveryNoteCreditResource
    {
        $companyId = CompanyPath::identifier($uriVariables, 'companyId');
        // The warning shows the customer's account, which the statement shows only to whoever may read both.
        $this->guard->companyForActing($companyId, CustomerPermission::READ);
        $this->guard->companyForActing($companyId, InvoicePermission::READ);
        $company = $this->guard->companyForActing($companyId, DeliveryNotePermission::READ);
        $noteId = CompanyPath::identifier($uriVariables, 'deliveryNoteId');

        try {
            return DeliveryNoteCreditResource::of($noteId->toRfc4122(), $this->credit->of($company, $noteId));
        } catch (DeliveryNoteNotFound $absent) {
            throw new NotFoundHttpException('No such delivery note.', $absent);
        } catch (InvalidDeliveryNote $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }
    }
}
