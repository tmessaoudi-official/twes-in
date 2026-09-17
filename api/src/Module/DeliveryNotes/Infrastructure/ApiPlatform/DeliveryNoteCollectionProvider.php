<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\DeliveryNotes\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Module\DeliveryNotes\Application\DeliveryNoteTotals;
use App\Module\DeliveryNotes\Application\ManageDeliveryNotes;
use App\Module\DeliveryNotes\Domain\DeliveryNote;
use App\Module\DeliveryNotes\Domain\DeliveryNoteSearch;
use App\Module\DeliveryNotes\Domain\DeliveryNoteStatus;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

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
        $status = Paging::value($operation, 'status');
        // The `uuid` format has already refused anything that is not an identifier, with a 422.
        $customer = Paging::value($operation, 'customerId');
        $search = new DeliveryNoteSearch(
            Paging::text($operation),
            \is_string($status) ? DeliveryNoteStatus::from($status) : null,
            \is_string($customer) ? Uuid::fromString($customer) : null,
            Paging::order($operation, DeliveryNoteSearch::SORTS),
        );

        return $this->paging->paginator(
            $this->manage->search($company, $search, $this->paging->request($operation, $context)),
            fn (DeliveryNote $note): DeliveryNoteResource => DeliveryNoteResource::of($note, $this->totals->of($note)),
        );
    }
}
