<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Tenancy\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Application\Company\PlatformCompanies;
use App\Tenancy\Application\Company\UnknownCompanyStatus;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** @implements ProviderInterface<PlatformCompanyResource> */
final readonly class PlatformCompanyCollectionProvider implements ProviderInterface
{
    public function __construct(private PlatformCompanies $companies)
    {
    }

    /** @return list<PlatformCompanyResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $filters = $context['filters'] ?? [];
        $status = \is_array($filters) ? ($filters['status'] ?? null) : null;
        if (null !== $status && !\is_string($status)) {
            throw new BadRequestHttpException('status: expected a single value.');
        }

        try {
            $views = $this->companies->byStatus($status);
        } catch (UnknownCompanyStatus $unknown) {
            throw new BadRequestHttpException($unknown->getMessage(), $unknown);
        }

        return array_map(PlatformCompanyResource::of(...), $views);
    }
}
