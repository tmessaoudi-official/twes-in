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
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Venue\Application\ArrangeVenue;
use App\Venue\Application\VenueAreaNotFound;
use App\Venue\Application\VenueStructureNotFound;
use App\Venue\Domain\InvalidVenue;
use App\Venue\Domain\PlanRect;
use App\Venue\Domain\StructureKind;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Drawing a piece of the building and correcting one, which are the same form — the kind included, because what a
 * piece IS is as revisable as where it stands.
 *
 * @implements ProcessorInterface<StockStructureResource, StockStructureResource>
 */
final readonly class BuildStockStructureProcessor implements ProcessorInterface
{
    public function __construct(private ArrangeVenue $venue, private CompanyGuard $guard)
    {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StockStructureResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), StockPermission::WRITE);
        $actor = $this->guard->account()->getId();

        try {
            $rect = new PlanRect($data->x, $data->y, $data->width, $data->depth, $data->rotation, $data->height);
            // Validated by the resource's own Choice constraint before it reaches here, so anything else would be
            // a surface that stopped checking rather than something a request could have sent.
            $kind = StructureKind::tryFrom($data->kind)
                ?? throw new InvalidVenue('kind', 'A piece of structure is a wall, a door, a post or a dock.');
            $built = $operation instanceof Post
                ? $this->venue->build($company, CompanyPath::identifier($uriVariables, 'floorId'), $kind, $data->name, $rect, $actor)
                : $this->venue->reshapeStructure($company, CompanyPath::identifier($uriVariables, 'structureId'), $kind, $data->name, $rect, $actor);
        } catch (VenueAreaNotFound|VenueStructureNotFound $absent) {
            throw new NotFoundHttpException($absent->getMessage(), $absent);
        } catch (InvalidVenue $refused) {
            throw new UnprocessableEntityHttpException(\sprintf('%s: %s', $refused->field, $refused->getMessage()), $refused);
        }

        return StockStructureResource::of($built);
    }
}
