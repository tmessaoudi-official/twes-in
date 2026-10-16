<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\Model\DeliveryNote;
use App\Module\DeliveryNotes\Domain\ValueObject\DeliveryNoteSearch;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Payload\DeliveryNoteSearchReader;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Resource\DeliveryNoteResource;
use App\Module\DeliveryNotes\Infrastructure\ApiPlatform\Security\DeliveryNotePermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * One page of a company's delivery notes, searched, narrowed and sorted in the database (docs/SPEC.md § 7, lists at
 * scale). Each row's figures are worked out for the page alone, so what the list costs does not grow with the ledger.
 *
 * @implements ProviderInterface<DeliveryNoteResource>
 */
final readonly class DeliveryNoteCollectionProvider implements ProviderInterface
{
    public function __construct(
        private ManageDeliveryNotes $manage,
        private DeliveryNoteTotals $totals,
        private CompanyGuard $guard,
        private Paging $paging,
    ) {
    }

    /** @return TraversablePaginator<DeliveryNoteResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), DeliveryNotePermission::READ);
        $search = DeliveryNoteSearchReader::read(Paging::parameters($context), Paging::text($operation), Paging::order($operation, DeliveryNoteSearch::SORTS), $company);

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            fn (DeliveryNote $note): DeliveryNoteResource => DeliveryNoteResource::of($note, $this->totals->of($note)),
        );
    }
}
