<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Module\Reports\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Module\Reports\Application\ReportCatalogue;
use App\Module\Reports\Infrastructure\ApiPlatform\Payload\ReportContexts;
use App\Module\Reports\Infrastructure\ApiPlatform\Resource\ReportCatalogueResource;

/** @implements ProviderInterface<ReportCatalogueResource> */
final readonly class ReportCatalogueProvider implements ProviderInterface
{
    public function __construct(private ReportContexts $contexts, private ReportCatalogue $catalogue)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ReportCatalogueResource
    {
        $reading = $this->contexts->of($uriVariables, null);

        return ReportCatalogueResource::of($this->catalogue->offered($reading), $reading);
    }
}
