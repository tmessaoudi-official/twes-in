<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * SPDX-FileCopyrightText: Takieddine MESSAOUDI
 */

declare(strict_types=1);

namespace App\Watch\Infrastructure\ApiPlatform\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Tenancy\Infrastructure\ApiPlatform\Payload\CompanyPath;
use App\Tenancy\Infrastructure\ApiPlatform\Security\CompanyGuard;
use App\Watch\Application\WatchCatalogue;
use App\Watch\Infrastructure\ApiPlatform\Resource\WatchResource;
use Psr\Clock\ClockInterface;

/** @implements ProviderInterface<WatchResource> */
final readonly class WatchProvider implements ProviderInterface
{
    /** Anyone who may read the company may read what to watch in it; each subject then asks its own permission. */
    public const string PERMISSION = 'company.read';

    public function __construct(private CompanyGuard $guard, private WatchCatalogue $catalogue, private ClockInterface $clock)
    {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): WatchResource
    {
        $company = $this->guard->companyForActing(CompanyPath::identifier($uriVariables, 'companyId'), self::PERMISSION);
        $now = $this->clock->now();
        $zone = new \DateTimeZone($company->getTimezone());
        $day = $now->setTimezone($zone)->format('Y-m-d');
        $today = new \DateTimeImmutable($day);
        $nextDay = new \DateTimeImmutable($day.' 00:00:00', $zone)->modify('+1 day');
        $may = fn (string $permission): bool => $this->guard->may($company, $permission);

        $readAgainAt = $this->catalogue->readAgainAt($company, $now, $nextDay, $may);

        return WatchResource::of($this->catalogue->counts($company, $today, $may), $readAgainAt->getTimestamp() - $now->getTimestamp());
    }
}
