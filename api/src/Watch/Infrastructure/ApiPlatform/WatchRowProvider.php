<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\Pagination\TraversablePaginator;
use ApiPlatform\State\ProviderInterface;
use App\Shared\Infrastructure\ApiPlatform\Paging;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Watch\Application\WatchCatalogue;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One page of one subject of « À surveiller », cut in the database (docs/SPEC.md § 7, the subject pages).
 *
 * @implements ProviderInterface<WatchRowResource>
 */
final readonly class WatchRowProvider implements ProviderInterface
{
    public function __construct(private CompanyGuard $guard, private WatchCatalogue $catalogue, private ClockInterface $clock, private Paging $paging)
    {
    }

    /** @return TraversablePaginator<WatchRowResource> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): TraversablePaginator
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), WatchProvider::PERMISSION);
        $kind = $uriVariables['kind'] ?? null;
        $declaration = \is_string($kind) ? $this->catalogue->declarationOf($kind, $company, fn (string $permission): bool => $this->guard->may($company, $permission)) : null;
        if (!\is_string($kind) || null === $declaration) {
            throw new NotFoundHttpException('There is nothing to watch by that name.');
        }
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));

        return $this->paging->paginator(
            $declaration->page($kind, $company, $today, $this->paging->request($operation, $context)),
            WatchRowResource::of(...),
        );
    }
}
