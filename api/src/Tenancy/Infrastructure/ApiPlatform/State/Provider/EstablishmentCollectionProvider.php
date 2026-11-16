<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Establishment\ManageEstablishments;
use App\Tenancy\Domain\Model\Establishment;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\CompanyProfileResource;
use App\Tenancy\Infrastructure\ApiPlatform\Resource\EstablishmentResource;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;

/**
 * The establishments, read under the company's own permission or under the customer screen's, product.read: a role made
 * for the screen holds the second alone and picks its place from this list, which would otherwise answer it as a stranger.
 *
 * @implements ProviderInterface<EstablishmentResource>
 */
final readonly class EstablishmentCollectionProvider implements ProviderInterface
{
    /** The customer screen's own read, named here as the products module grants it (Tenancy imports no module). */
    private const string SCREEN_PERMISSION = 'product.read';

    public function __construct(private ManageEstablishments $manage, private CompanyGuard $guard)
    {
    }

    /** @return list<EstablishmentResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActingUnderAny(CompanyPath::identifier($uriVariables, 'companyId'), [CompanyProfileResource::READ_PERMISSION, self::SCREEN_PERMISSION]);
        $pattern = $this->manage->codePattern($company);

        return array_map(fn (Establishment $establishment) => EstablishmentResource::of($establishment, $pattern, $this->manage->isCodeLocked($company, $establishment), $this->manage->contactsToCheck($company, $establishment)), $this->manage->list($company));
    }
}
