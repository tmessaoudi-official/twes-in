<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\ManageStockLocations;
use App\Module\Inventory\Application\StockLocationCodeTaken;
use App\Module\Inventory\Application\StockLocationNotFound;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/** @implements ProcessorInterface<StockLocationResource, StockLocationResource> */
final readonly class ReviseStockLocationProcessor implements ProcessorInterface
{
    public function __construct(private ManageStockLocations $manage, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockLocationResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $id = CompanyPath::identifier($uriVariables, 'locationId');

        try {
            $location = $this->manage->get($company, $id);
            if (!$location->getEstablishment()->getId()->equals(Uuid::fromString($data->establishmentId))) {
                throw new InvalidStockLocation('establishmentId', 'A location stays in its establishment.');
            }
            $this->manage->revise(
                $company,
                $id,
                null === $data->parentId ? null : Uuid::fromString($data->parentId),
                StockLocationKind::from($data->kind),
                $data->code,
                $data->name,
                $this->guard->account()->getId(),
            );
        } catch (StockLocationNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (StockLocationCodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidStockLocation $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockLocationResource::of($location, $this->manage->childCount($location), $this->manage->movementCount($location));
    }
}
