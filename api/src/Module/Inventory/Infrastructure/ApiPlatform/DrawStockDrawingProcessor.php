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
use App\Module\Inventory\Application\StockLocationCodeTaken;
use App\Module\Inventory\Application\StockLocationNotFound;
use App\Module\Inventory\Domain\InvalidStockLocation;
use App\Module\Inventory\Domain\StockLocationKind;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Application\VenueSpotNotFound;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Drawing a rectangle on a floor and moving one, which are the same form. A location that is none of this company's
 * answers 422 naming `locationId` rather than 404: it is a field of what was sent, not the thing being addressed,
 * and a drawing tool says which box is wrong.
 *
 * @implements ProcessorInterface<StockDrawingResource, StockDrawingResource>
 */
final readonly class DrawStockDrawingProcessor implements ProcessorInterface
{
    public function __construct(private DrawStockMap $map, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockDrawingResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $actor = $this->guard->account()->getId();

        // A rectangle names its place one way: an existing one, or a new one created with it. Moving a rectangle never
        // creates a place, since giving it another place is choosing among those that exist.
        $creates = null !== $data->newLocationKind || null !== $data->newLocationCode || null !== $data->newLocationName;
        if ($creates && ('' !== $data->locationId || !$operation instanceof Post)) {
            throw new UnprocessableEntityHttpException('newLocationCode: A rectangle is drawn for an existing place or for a new one, and moving it never creates one.');
        }
        if (!$creates && '' === $data->locationId) {
            throw new UnprocessableEntityHttpException('locationId: A rectangle is drawn for an existing place or for a new one.');
        }

        try {
            $rect = new PlanRect($data->x, $data->y, $data->width, $data->depth, $data->rotation, $data->height);
            if ($creates) {
                $kind = StockLocationKind::tryFrom((string) $data->newLocationKind)
                    ?? throw new UnprocessableEntityHttpException('newLocationKind: A place is a zone, a rack or another kind the plan carries.');
                $drawn = $this->map->drawNew($company, CompanyPath::identifier($uriVariables, 'floorId'), $kind, (string) $data->newLocationCode, (string) $data->newLocationName, $rect, $actor);
            } else {
                $location = Uuid::fromString($data->locationId);
                $drawn = $operation instanceof Post
                    ? $this->map->draw($company, CompanyPath::identifier($uriVariables, 'floorId'), $location, $rect, $actor)
                    : $this->map->moveDrawing($company, CompanyPath::identifier($uriVariables, 'drawingId'), $location, $rect, $actor);
            }
        } catch (VenueAreaNotFound|VenueSpotNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (StockLocationCodeTaken $taken) {
            throw new ConflictHttpException($taken->getMessage(), $taken);
        } catch (StockLocationNotFound $unknown) {
            throw new UnprocessableEntityHttpException(\sprintf('locationId: %s', $unknown->getMessage()), $unknown);
        } catch (InvalidVenue $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        } catch (InvalidStockLocation $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockDrawingResource::ofDrawn($drawn);
    }
}
