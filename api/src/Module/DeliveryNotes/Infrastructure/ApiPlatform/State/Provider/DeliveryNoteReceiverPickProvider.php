<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteReceiverPickResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Shared\Application\Receivers\ReceiverContacts;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * The customer's contacts for the delivery note's « Reçu par », under the note's own permission (see the resource
 * beside this); none for a customer of another company.
 *
 * @implements ProviderInterface<DeliveryNoteReceiverPickResource>
 */
final readonly class DeliveryNoteReceiverPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private ReceiverContacts $contacts)
    {
    }

    /** @return list<DeliveryNoteReceiverPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);

        return array_map(DeliveryNoteReceiverPickResource::of(...), $this->contacts->contactsOf($company, CompanyPath::identifier($uriVariables, 'customerId')));
    }
}
