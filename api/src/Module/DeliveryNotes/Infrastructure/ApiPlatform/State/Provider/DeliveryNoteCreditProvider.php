<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Customers\Infrastructure\ApiPlatform\Security\CustomerPermission;
use App\Module\DeliveryNotes\Application\DeliveryNoteCredit;
use App\Module\DeliveryNotes\Application\DeliveryNoteNotFound;
use App\Module\DeliveryNotes\Domain\Exception\InvalidDeliveryNote;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteCreditResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Module\Invoices\Infrastructure\ApiPlatform\Security\InvoicePermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
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
