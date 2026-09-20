<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Invoices\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Products\Application\PickProducts;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\Uid\Uuid;

/**
 * A few products for the invoice form's picker, under the invoice's own permission (see the resource beside this).
 *
 * @implements ProviderInterface<InvoiceProductPickResource>
 */
final readonly class InvoiceProductPickProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private PickProducts $products)
    {
    }

    /** @return list<InvoiceProductPickResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), InvoicePermission::READ);

        $ids = self::ids($operation);

        return array_map(InvoiceProductPickResource::of(...), [] === $ids
            ? $this->products->matching($company, Paging::text($operation) ?? '')
            : $this->products->byIds($company, $ids));
    }

    /**
     * The uuids a request named, at most what a picker shows. A value that is not a uuid is left out rather than
     * refused: a form asking about a record that no longer exists should read as "not found", not as a bad request.
     *
     * @return list<Uuid>
     */
    private static function ids(Operation $operation): array
    {
        $given = Paging::value($operation, 'ids');
        $ids = [];
        foreach (\is_array($given) ? $given : [] as $value) {
            if (\is_string($value) && Uuid::isValid($value)) {
                $ids[] = Uuid::fromString($value);
            }
        }

        return $ids;
    }
}
