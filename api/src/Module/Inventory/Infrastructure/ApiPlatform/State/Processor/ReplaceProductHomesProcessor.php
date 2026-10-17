<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\KeepProductHomes;
use App\Module\Inventory\Application\ProductNotInCompany;
use App\Module\Inventory\Domain\Exception\InvalidStockLocation;
use App\Module\Inventory\Infrastructure\ApiPlatform\Resource\ProductHomeResource;
use App\Module\Products\Infrastructure\ApiPlatform\Security\ProductPermission;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the homes of one establishment as a whole list, in order, the first the main one. 204: what the screen shows
 * afterwards is read again, so nothing here is a second source for it.
 *
 * @implements ProcessorInterface<ProductHomeResource, null>
 */
final readonly class ReplaceProductHomesProcessor implements ProcessorInterface
{
    public function __construct(private KeepProductHomes $homes, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), ProductPermission::WRITE);
        try {
            $this->homes->replace(
                $company,
                CompanyPath::identifier($uriVariables, 'productId'),
                CompanyPath::identifier($uriVariables, 'establishmentId'),
                array_map(static fn (string $id): Uuid => Uuid::fromString($id), $data->locationIds),
                $this->guard->account()->getId(),
                $data->version,
            );
        } catch (ProductNotInCompany $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidStockLocation $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return null;
    }
}
