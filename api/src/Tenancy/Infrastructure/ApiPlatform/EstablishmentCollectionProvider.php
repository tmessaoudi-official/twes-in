<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Establishment;

/** @implements ProviderInterface<EstablishmentResource> */
final readonly class EstablishmentCollectionProvider implements ProviderInterface
{
    public function __construct(private ManageEstablishments $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<EstablishmentResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), CompanyProfileResource::READ_PERMISSION);
        $pattern = $this->manage->codePattern($company);

        return array_map(static fn (Establishment $establishment) => EstablishmentResource::of($establishment, $pattern), $this->manage->list($company));
    }
}
