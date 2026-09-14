<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;

/** @implements ProviderInterface<DeliveryNoteResource> */
final readonly class DeliveryNoteCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageDeliveryNotes $manage, private DeliveryNoteTotals $totals, private CompanyGuard $guard)
    {
    }

    /** @return list<DeliveryNoteResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);

        return array_map(fn (DeliveryNote $note) => DeliveryNoteResource::of($note, $this->totals->of($note)), $this->manage->list($company));
    }
}
