<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Inventory\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Module\Inventory\Application\DrawStockMap;
use App\Module\Inventory\Application\StockLocationCodeTaken;
use App\Module\Inventory\Application\StockLocationNotFound;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocation;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\VenueSpotNotFound;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanWay;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Repeating a rectangle down an aisle. The refusals are named by the FIELD they belong to, because this is a form
 * with four boxes in it and a person given "invalid" has to guess which one to change: a taken code answers 409 with
 * the code in it, a step off the floor 422 naming `x` or `y`, an impossible count or spacing 422 naming itself.
 *
 * @implements ProcessorInterface<RepeatStockDrawingResource, RepeatStockDrawingResource>
 */
final readonly class RepeatStockDrawingProcessor implements ProcessorInterface
{
    public function __construct(private DrawStockMap $map, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RepeatStockDrawingResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $actor = $this->guard->account()->getId();

        try {
            $copies = $this->map->repeat(
                $company,
                CompanyPath::identifier($uriVariables, 'drawingId'),
                $data->count,
                $data->spacing,
                PlanWay::from($data->way),
                $data->firstCode,
                $actor,
            );
        } catch (VenueSpotNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (StockLocationCodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (StockLocationNotFound $unknown) {
            throw new UnprocessableEntityHttpException(\sprintf('drawingId: %s', $unknown->getMessage()), $unknown);
        } catch (InvalidVenue|InvalidStockLocation $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        $data->drawings = array_map(
            static fn (StockLocation $copy): StockDrawingResource => StockDrawingResource::ofDrawn($copy),
            $copies,
        );

        return $data;
    }
}
