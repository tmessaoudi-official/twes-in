<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\Metadata\Post;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\DrawStockMap;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Application\VenueLevelTaken;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\VenueArea;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Adding a floor and revising one, which are the same form: its name, its level, and the plan shown behind the
 * drawing. A second floor at the same level answers 409, since that would be two plans of one place.
 *
 * @implements ProcessorInterface<StockFloorResource, StockFloorResource>
 */
final readonly class WriteStockFloorProcessor implements ProcessorInterface
{
    public function __construct(private DrawStockMap $map, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockFloorResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $actor = $this->guard->account()->getId();
        $image = null === $data->imageFileId ? null : Uuid::fromString($data->imageFileId);

        try {
            $floor = $operation instanceof Post
                ? $this->map->addFloor($company, Uuid::fromString($data->establishmentId), $data->name, $data->level, (string) $data->widthMetres, (string) $data->depthMetres, $actor)
                : $this->map->reviseFloor($company, CompanyPath::identifier($uriVariables, 'floorId'), $data->name, $data->level, (string) $data->widthMetres, (string) $data->depthMetres, $image, $data->imageMetresWide, $data->imageOpacity, $actor);
        } catch (VenueAreaNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (VenueLevelTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (InvalidVenue $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockFloorResource::of($floor, $this->drawingCount($floor));
    }

    private function drawingCount(VenueArea $floor): int
    {
        return \count($this->map->drawingsOf($floor->getCompany(), $floor->getId()));
    }
}
