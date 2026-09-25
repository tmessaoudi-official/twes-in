<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Infrastructure\ApiPlatform;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyGuard;
use App\Tenancy\Infrastructure\ApiPlatform\CompanyPath;
use App\Watch\Application\WatchCatalogue;
use Psr\Clock\ClockInterface;

/** @implements ProviderInterface<WatchResource> */
final readonly class WatchProvider implements ProviderInterface
{
    /** Anyone who may read the company may read what to watch in it; each condition then asks its own permission. */
    private const string PERMISSION = 'company.read';

    public function __construct(private CompanyGuard $guard, private WatchCatalogue $catalogue, private ClockInterface $clock)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WatchResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), self::PERMISSION);
        $today = new \DateTimeImmutable($this->clock->now()->setTimezone(new \DateTimeZone($company->getTimezone()))->format('Y-m-d'));

        return WatchResource::of($this->catalogue->itemsFor($company, $today, fn (string $permission): bool => $this->guard->may($company, $permission)));
    }
}
