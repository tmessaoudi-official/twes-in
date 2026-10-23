<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Inventory\Application\Count\ReadCountContext;
use App\Module\Inventory\Domain\ValueObject\StockLevel;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\StockCountContextResource;
use App\Module\Inventory\Infrastructure\ApiPlatform\Security\StockPermission;
use App\Shared\Infrastructure\ApiPlatform\Payload\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProviderInterface<StockCountContextResource> */
final readonly class StockCountContextProvider implements ProviderInterface
{
    public function __construct(private ReadCountContext $context, private CompanyGuard $guard)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StockCountContextResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::READ);
        $asked = Paging::uuids($operation, 'productIds');
        // Answering the first hundred alone would leave a longer sheet's last lines saying nothing, as if all were well.
        if (\count($asked) > ReadCountContext::MOST) {
            throw new UnprocessableEntityHttpException(\sprintf('A count asks about at most %d products at once.', ReadCountContext::MOST));
        }

        $resource = new StockCountContextResource();
        $resource->items = array_map(static fn (array $row): array => [
            'productId' => $row['product']->getId()->toRfc4122(),
            'places' => array_map(static fn (StockLevel $level): array => [
                'locationId' => $level->locationId->toRfc4122(),
                'lotCode' => $level->lotCode,
                'quantity' => $level->quantity,
            ], $row['levels']),
            'homeLocationIds' => array_map(static fn (Uuid $id): string => $id->toRfc4122(), $row['homes']),
        ], $this->context->of($company, $asked));

        return $resource;
    }
}
